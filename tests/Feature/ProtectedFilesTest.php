<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\ProtectedFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProtectedFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeProtectedStorage();
    }

    public function test_guests_ordinary_staff_and_inactive_staff_cannot_use_file_apis(): void
    {
        $this->assertApiFailure($this->getJson('/api/v1/files'), 401, 'unauthenticated');
        $this->postCommand('/api/v1/files', [])->assertUnauthorized();
        $ordinary = $this->createStaffWithRole('doctor');
        $this->actingAs($ordinary)->getJson('/api/v1/files')->assertForbidden();
        $this->postCommand('/api/v1/files', ['file' => $this->pdf()])->assertForbidden();
        $this->getJson('/api/v1/files/1')->assertForbidden();
        $this->getJson('/api/v1/files/1/download')->assertForbidden();
        $this->grantRolePermissions('doctor', ['files.upload', 'files.view_own']);
        $ordinary->forceFill(['is_active' => false])->save();
        $this->assertApiFailure($this->getJson('/api/v1/files'), 401, 'unauthenticated');
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], Storage::disk('protected')->allFiles());
    }

    public function test_upload_requires_both_own_view_and_upload_permissions(): void
    {
        $user = $this->createStaffWithRole('doctor');
        $this->grantRolePermissions('doctor', ['files.view_own']);
        $this->actingAs($user)->getJson('/api/v1/files')->assertOk();
        $this->postCommand('/api/v1/files', ['file' => $this->pdf()])->assertForbidden();
        Role::where('code', 'doctor')->sole()->permissions()->detach(Permission::where('code', 'files.view_own')->sole());
        $this->grantRolePermissions('doctor', ['files.upload']);
        $this->postCommand('/api/v1/files', ['file' => $this->pdf()])->assertForbidden();
        $this->assertDatabaseCount('files', 0);
    }

    public function test_upload_persists_private_bytes_and_server_owned_metadata_without_exposing_paths_or_hashes(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $upload = $this->pdf('Confidential-report.pdf');
        $content = file_get_contents($upload->getRealPath());
        $response = $this->actingAs($owner)->postCommand('/api/v1/files', ['file' => $upload])->assertCreated()
            ->assertJsonPath('data.original_name', 'Confidential-report.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')->assertJsonPath('data.byte_size', strlen($content))
            ->assertJsonPath('data.uploaded_by', $owner->id)->assertJsonPath('data.scan_status', 'not_scanned')
            ->assertJsonMissingPath('data.storage_path')->assertJsonMissingPath('data.sha256')
            ->assertHeader('Cache-Control', 'no-store, private');
        $record = DB::table('files')->sole();
        $this->assertSame($owner->id, (int) $record->uploaded_by);
        $this->assertSame(hash('sha256', $content), $record->sha256);
        $this->assertMatchesRegularExpression('/^\d{4}\/\d{2}\/[a-f0-9-]{36}\.pdf$/', $record->storage_path);
        Storage::disk('protected')->assertExists($record->storage_path);
        $this->assertSame($content, Storage::disk('protected')->get($record->storage_path));
        $this->assertAuditEventRecorded('file.uploaded', $owner->id, 'file', (int) $record->id, summary: ['byte_size' => strlen($content), 'mime_type' => 'application/pdf']);
        $this->assertStringNotContainsString('Confidential-report', DB::table('audit_events')->get()->toJson());
        $this->assertStringNotContainsString($record->storage_path, DB::table('audit_events')->get()->toJson());
        $this->assertSame((int) $record->id, $response->json('data.id'));
    }

    public function test_file_list_and_metadata_are_strictly_owner_scoped_even_for_administrators(): void
    {
        $this->grantRolePermissions('doctor', ['files.upload', 'files.view_own']);
        $first = $this->createStaffWithRole('doctor');
        $second = $this->createStaffWithRole('admin');
        $service = app(ProtectedFiles::class);
        $a = $service->upload($first, $this->pdf('First.pdf'));
        $b = $service->upload($first, $this->pdf('Second.pdf'));
        $other = $service->upload($second, $this->pdf('Other-owner.pdf'));
        $this->actingAs($first)->getJson('/api/v1/files?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', (int) $b->id);
        $this->getJson('/api/v1/files?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.id', (int) $a->id);
        $this->getJson('/api/v1/files/'.$a->id)->assertOk()->assertJsonPath('data.uploaded_by', $first->id);
        $this->getJson('/api/v1/files/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/files/'.$other->id.'/download')->assertNotFound();
        $this->flushSession();
        $this->actingAs($second)->getJson('/api/v1/files')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/files/'.$a->id)->assertNotFound();
        $this->getJson('/api/v1/files/'.$a->id.'/download')->assertNotFound();
        $this->getJson('/api/v1/files/999999')->assertNotFound();
    }

    public function test_download_returns_exact_bytes_as_a_private_attachment_with_security_headers(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $record = app(ProtectedFiles::class)->upload($owner, $this->pdf());
        $this->actingAs($owner)->get('/api/v1/files/'.$record->id.'/download')->assertOk()
            ->assertDownload('report.pdf')->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('Content-Length', (string) $record->byte_size)->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->assertStreamedContent(Storage::disk('protected')->get($record->storage_path));
        $this->assertDatabaseHas('audit_events', ['action' => 'file.download_authorized', 'actor_id' => $owner->id,
            'subject_id' => $record->id, 'outcome' => 'succeeded']);
    }

    #[DataProvider('badFiles')]
    public function test_empty_disallowed_spoofed_and_extension_mismatched_files_are_rejected(string $name, string $content): void
    {
        $owner = $this->createStaffWithRole('admin');
        $upload = UploadedFile::fake()->createWithContent($name, $content);
        $this->actingAs($owner)->postCommand('/api/v1/files', ['file' => $upload])->assertUnprocessable()
            ->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], Storage::disk('protected')->allFiles());
        $this->assertDatabaseHas('audit_events', ['action' => 'file.uploaded', 'outcome' => 'denied', 'http_status' => 422]);
    }

    public static function badFiles(): array
    {
        return [['empty.pdf', ''], ['script.pdf', '<?php echo "private";'], ['page.html', '<html>unsafe</html>'],
            ['vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'], ['archive.zip', 'PK'.str_repeat('x', 100)],
            ['picture.png', "%PDF-1.4\n%%EOF"], ['script.php', "%PDF-1.4\n%%EOF"], ['fake.jpg', 'not a jpeg']];
    }

    public function test_size_limits_missing_files_and_untrusted_metadata_are_validated(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $this->actingAs($owner)->postCommand('/api/v1/files', [])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postCommand('/api/v1/files', ['file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postCommand('/api/v1/files', ['file' => $this->pdf(), 'uploaded_by' => $owner->id + 1,
            'storage_path' => '../../public/script.php', 'sha256' => 'forged', 'scan_status' => 'clean',
            'mime_type' => 'text/html', 'byte_size' => 1, 'patient_id' => 7, 'encounter_id' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors(['uploaded_by', 'storage_path', 'sha256',
                'scan_status', 'mime_type', 'byte_size', 'patient_id', 'encounter_id']);
        $this->getJson('/api/v1/files?per_page=101&page=0')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'page']);
        $this->assertDatabaseCount('files', 0);
    }

    public function test_real_png_upload_is_supported_without_image_processing_dependencies(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=');
        $this->actingAs($owner)->postCommand('/api/v1/files', [
            'file' => UploadedFile::fake()->createWithContent('Pixel.PNG', $png),
        ])->assertCreated()->assertJsonPath('data.mime_type', 'image/png')->assertJsonPath('data.original_name', 'Pixel.png');
    }

    public function test_client_filenames_cannot_create_paths_or_inject_download_headers(): void
    {
        $owner = $this->createStaffWithRole('admin');
        foreach (["../../private\r\nHeader.pdf", 'NUL.pdf', str_repeat('long', 80).'.pdf'] as $name) {
            $record = app(ProtectedFiles::class)->upload($owner, $this->pdf($name));
            $this->assertLessThanOrEqual(184, strlen($record->original_name));
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\/\\\\]/', $record->original_name);
            $this->assertNotSame('NUL.pdf', $record->original_name);
            $this->assertStringNotContainsString('private', $record->storage_path);
        }
        $this->assertDatabaseCount('files', 3);
    }

    public function test_permission_revocation_takes_effect_on_metadata_and_downloads_and_seeding_preserves_it(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $record = app(ProtectedFiles::class)->upload($owner, $this->pdf());
        $this->actingAs($owner)->getJson('/api/v1/files/'.$record->id)->assertOk();
        Role::where('code', 'admin')->sole()->permissions()->detach(Permission::where('code', 'files.view_own')->sole());
        $this->seed(DatabaseSeeder::class);
        $this->getJson('/api/v1/files/'.$record->id)->assertForbidden();
        $this->getJson('/api/v1/files/'.$record->id.'/download')->assertForbidden();
        $this->postCommand('/api/v1/files', ['file' => $this->pdf()])->assertForbidden();
        $this->assertDatabaseCount('files', 1);
    }

    #[DataProvider('unavailableFiles')]
    public function test_missing_changed_or_quarantined_bytes_are_never_served(string $case): void
    {
        $owner = $this->createStaffWithRole('admin');
        $record = app(ProtectedFiles::class)->upload($owner, $this->pdf());
        if ($case === 'missing') {
            Storage::disk('protected')->delete($record->storage_path);
        } elseif ($case === 'changed') {
            Storage::disk('protected')->put($record->storage_path, 'Changed private content');
        } elseif ($case === 'path') {
            DB::table('files')->where('id', $record->id)->update(['storage_path' => '../../.env']);
        } else {
            DB::table('files')->where('id', $record->id)->update(['scan_status' => $case]);
        }
        $status = in_array($case, ['pending', 'blocked'], true) ? 409 : 503;
        $this->actingAs($owner)->getJson('/api/v1/files/'.$record->id.'/download')->assertStatus($status)
            ->assertJsonMissingPath('data')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseMissing('audit_events', ['action' => 'file.download_authorized', 'outcome' => 'succeeded']);
    }

    public static function unavailableFiles(): array
    {
        return [['missing'], ['changed'], ['path'], ['pending'], ['blocked']];
    }

    public function test_audit_failure_rolls_back_metadata_and_removes_the_uploaded_blob(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $this->mock(AuditTrail::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(ProtectedFiles::class)->upload($owner, $this->pdf());
            $this->fail('A file must not be published without its audit event.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], Storage::disk('protected')->allFiles());
    }

    public function test_a_failed_disk_write_cannot_commit_metadata_or_a_success_event(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $root = Storage::disk('protected')->path('');
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('path')->with('')->andReturn($root);
        $disk->shouldReceive('getConfig')->andReturn(['serve' => false]);
        $disk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('Disk unavailable'));
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('protected', $disk);
        try {
            app(ProtectedFiles::class)->upload($owner, $this->pdf());
            $this->fail('An incomplete disk write must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Disk unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseMissing('audit_events', ['action' => 'file.uploaded', 'outcome' => 'succeeded']);
    }

    public function test_database_failure_details_do_not_copy_private_filenames_into_the_reported_exception(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $exception = new QueryException('testing', 'insert into files (original_name) values (?)',
            ['Private-patient-name.pdf'], new RuntimeException('Private database details'));
        $this->mock(AuditTrail::class)->shouldReceive('record')->once()->andThrow($exception);
        try {
            app(ProtectedFiles::class)->upload($owner, $this->pdf('Private-patient-name.pdf'));
            $this->fail('The failed private operation must not commit.');
        } catch (RuntimeException $reported) {
            $this->assertSame('A private file operation could not complete.', $reported->getMessage());
            $this->assertNull($reported->getPrevious());
        }
        $this->assertDatabaseCount('files', 0);
        $this->assertSame([], Storage::disk('protected')->allFiles());
    }

    public function test_audit_failure_prevents_a_download_response(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $record = app(ProtectedFiles::class)->upload($owner, $this->pdf());
        $this->mock(AuditTrail::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->expectException(RuntimeException::class);
        app(ProtectedFiles::class)->openDownload($owner, (int) $record->id);
    }

    public function test_file_ownership_cannot_be_removed_by_deleting_staff(): void
    {
        $owner = $this->createStaffWithRole('admin');
        app(ProtectedFiles::class)->upload($owner, $this->pdf());
        try {
            $owner->delete();
            $this->fail('Referenced upload owners must be retained.');
        } catch (QueryException) {
            $this->assertDatabaseCount('files', 1);
            $this->assertDatabaseHas('users', ['id' => $owner->id]);
        }
    }

    public function test_private_storage_has_no_served_url_and_refuses_a_public_root(): void
    {
        $this->assertFalse(config('filesystems.disks.protected.serve'));
        $this->assertNotSame(config('filesystems.disks.local.root'), config('filesystems.disks.protected.root'));
        $disk = Storage::build(['driver' => 'local', 'root' => public_path(), 'serve' => false]);
        Storage::set('protected', $disk);
        $this->expectException(RuntimeException::class);
        app(ProtectedFiles::class)->disk();
    }

    public function test_read_only_verification_detects_missing_corrupt_and_orphaned_blobs(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $service = app(ProtectedFiles::class);
        $missing = $service->upload($owner, $this->pdf());
        $corrupt = $service->upload($owner, $this->pdf());
        Storage::disk('protected')->delete($missing->storage_path);
        Storage::disk('protected')->put($corrupt->storage_path, 'Damaged private bytes');
        Storage::disk('protected')->put('orphan.pdf', 'unreferenced private bytes');
        $this->artisan('files:verify')->expectsOutput('Checked: 2; missing: 1; corrupt: 1; orphaned: 1.')
            ->expectsOutput('File verification found inconsistencies. No records or files were changed.')->assertFailed();
        $this->assertDatabaseCount('files', 2);
        Storage::disk('protected')->assertExists('orphan.pdf');
        $this->assertDatabaseHas('audit_events', ['action' => 'file.integrity_checked', 'channel' => 'console', 'outcome' => 'failed']);
    }

    public function test_clean_storage_verification_passes_without_modifying_file_metadata(): void
    {
        $owner = $this->createStaffWithRole('admin');
        app(ProtectedFiles::class)->upload($owner, $this->pdf());
        $before = DB::table('files')->get()->toJson();
        $this->artisan('files:verify')->expectsOutput('Checked: 1; missing: 0; corrupt: 0; orphaned: 0.')
            ->expectsOutput('File verification passed.')->assertSuccessful();
        $this->assertSame($before, DB::table('files')->get()->toJson());
    }

    public function test_upload_rate_limit_blocks_excess_writes_without_creating_records(): void
    {
        $owner = $this->createStaffWithRole('admin');
        $this->actingAs($owner);
        for ($attempt = 0; $attempt < 11; $attempt++) {
            $response = $this->postCommand('/api/v1/files', []);
        }
        $response->assertTooManyRequests();
        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'file.uploaded', 'outcome' => 'denied', 'http_status' => 429]);
    }

    private function pdf(string $name = 'report.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    }
}
