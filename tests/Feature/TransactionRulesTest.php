<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\ProtectedFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TransactionRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $key = 'test-command-00000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeProtectedStorage();
        $this->admin = $this->createStaffWithRole('admin');
        $this->actingAs($this->admin);
    }

    public function test_sensitive_writes_require_a_well_formed_request_key(): void
    {
        foreach ([[], ['Idempotency-Key' => 'short'], ['Idempotency-Key' => str_repeat('x', 129)], ['Idempotency-Key' => 'invalid key with spaces']] as $headers) {
            $this->postJson('/api/v1/staff-management', $this->data(), $headers)
                ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        }
        $this->assertDatabaseCount('idempotency_requests', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_lost_create_response_can_be_confirmed_before_unique_validation_without_a_second_write(): void
    {
        $first = $this->create()->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->create()->assertOk()->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'staff.created')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'command.replayed')->count());
    }

    public function test_json_property_order_does_not_change_command_identity(): void
    {
        $first = $this->create()->assertCreated();
        $data = array_reverse($this->data(), true);
        $this->postCommand('/api/v1/staff-management', $data, $this->headers())
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    }

    public function test_a_request_key_cannot_be_reused_for_different_data(): void
    {
        $this->create()->assertCreated();
        $this->create(['first_name' => 'Different'])->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame('Synthetic', User::where('username', 'transaction.staff')->sole()->first_name);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_replay_rechecks_current_password_and_does_not_store_credentials_or_payloads(): void
    {
        $this->create()->assertCreated();
        $this->create(['current_password' => 'Wrong-secret-123!'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->create()->assertOk();
        $stored = DB::table('idempotency_requests')->get()->toJson();
        foreach (['Correct-secret-123!', 'Synthetic', 'transaction.staff', $this->key] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $stored);
        }
    }

    public function test_permission_revocation_blocks_replay(): void
    {
        $this->create()->assertCreated();
        Role::where('code', 'admin')->sole()->permissions()->detach(Permission::where('code', 'staff.manage')->sole());
        $this->create()->assertForbidden();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_keys_are_scoped_to_the_actor(): void
    {
        $this->create()->assertCreated();
        $other = $this->createStaffWithRole('admin');
        $this->flushSession();
        $this->actingAs($other);
        $this->create(['username' => 'another.staff'])->assertCreated();
        $this->assertDatabaseCount('idempotency_requests', 2);
        $this->assertDatabaseCount('users', 4);
    }

    public function test_an_update_replay_does_not_increment_version_or_repeat_audit(): void
    {
        $target = $this->createStaffWithRole('doctor');
        $data = collect($this->data())->except('username')->all() + ['version' => 1];
        $url = '/api/v1/staff-management/'.$target->id;
        $this->patchCommand($url, $data, $this->headers())->assertOk()->assertJsonPath('data.version', 2);
        $this->patchCommand($url, $data, $this->headers())->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->patchCommand($url, $data)->assertConflict()->assertJsonPath('code', 'conflict');
        $this->assertSame(2, $target->fresh()->version);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'staff.updated')->where('outcome', 'succeeded')->count());
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_a_key_cannot_replay_an_update_for_another_target(): void
    {
        $first = $this->createStaffWithRole('doctor');
        $second = $this->createStaffWithRole('doctor');
        $data = collect($this->data())->except('username')->all() + ['version' => 1];
        $this->patchCommand('/api/v1/staff-management/'.$first->id, $data, $this->headers())->assertOk();
        $this->patchCommand('/api/v1/staff-management/'.$second->id, $data, $this->headers())
            ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame(1, $second->fresh()->version);
    }

    public function test_expired_keys_are_tombstones_and_never_start_a_new_write(): void
    {
        $this->create()->assertCreated();
        $this->travel(25)->hours();
        $this->create()->assertConflict()->assertJsonPath('code', 'idempotency_expired');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_a_completed_command_does_not_replay_a_later_staff_version(): void
    {
        $id = $this->create()->assertCreated()->json('data.id');
        User::whereKey($id)->update(['version' => 2]);
        $this->create()->assertConflict()->assertJsonPath('code', 'idempotency_outdated');
        $this->assertDatabaseCount('users', 2);
    }

    public function test_failed_validation_releases_the_key_and_saves_nothing(): void
    {
        $this->create(['first_name' => ''])->assertUnprocessable();
        $this->assertDatabaseCount('idempotency_requests', 0);
        $this->create()->assertCreated();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_a_recovery_code_is_not_persisted_or_redisplayed_by_a_retry(): void
    {
        $target = $this->createStaffWithRole('doctor');
        $url = '/api/v1/staff-management/'.$target->id.'/password-reset';
        $data = ['version' => 1, 'identity_verified' => true, 'current_password' => 'Correct-secret-123!'];
        $code = $this->postCommand($url, $data, $this->headers())->assertCreated()->json('data.reset_code');
        $this->postCommand($url, $data, $this->headers())->assertConflict()->assertJsonPath('code', 'idempotency_completed')
            ->assertJsonMissingPath('data.reset_code');
        $this->assertSame(2, $target->fresh()->version);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'staff.recovery_issued')->where('outcome', 'succeeded')->count());
        $this->assertStringNotContainsString($code, DB::table('idempotency_requests')->get()->toJson());
    }

    public function test_a_failed_completion_record_rolls_back_the_staff_and_its_success_audit(): void
    {
        $triggered = $this->failNextWriteTo('idempotency_requests', 'update');
        $response = $this->assertTablesUnchanged(['users', 'user_roles', 'staff_password_resets', 'sessions', 'idempotency_requests'], fn () => $this->create());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertTrue($triggered());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseCount('idempotency_requests', 0);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'staff.created')->where('outcome', 'succeeded')->count());
        $this->create()->assertCreated();
    }

    public function test_a_repeated_upload_confirms_one_file_and_one_blob(): void
    {
        $first = $this->upload()->assertCreated();
        $this->upload()->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('files', 1);
        $this->assertCount(1, Storage::disk('protected')->allFiles());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'file.uploaded')->count());
    }

    public function test_upload_identity_includes_actual_bytes_and_filename(): void
    {
        $this->upload()->assertCreated();
        $this->upload('different.pdf')->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->upload(content: "%PDF-1.4\nDifferent synthetic document\n%%EOF")->assertConflict();
        $this->assertDatabaseCount('files', 1);
        $this->assertCount(1, Storage::disk('protected')->allFiles());
    }

    public function test_an_intentional_new_upload_uses_a_new_key_even_for_identical_content(): void
    {
        $this->upload()->assertCreated();
        $this->key = 'test-command-00000002';
        $this->upload()->assertCreated();
        $this->assertDatabaseCount('files', 2);
        $this->assertCount(2, Storage::disk('protected')->allFiles());
    }

    public function test_completion_failure_after_a_file_write_rolls_back_metadata_and_removes_the_blob(): void
    {
        $triggered = $this->failNextWriteTo('idempotency_requests', 'update');
        $response = $this->assertTablesUnchanged(['files', 'idempotency_requests'], fn () => $this->upload());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertTrue($triggered());
        $this->assertDatabaseCount('files', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
        $this->assertCount(0, Storage::disk('protected')->allFiles());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'file.uploaded')->where('outcome', 'succeeded')->count());
        $this->upload()->assertCreated();
    }

    public function test_cleanup_preserves_a_referenced_blob_when_a_commit_may_have_succeeded(): void
    {
        $this->upload()->assertCreated();
        $file = DB::table('files')->sole();
        app(ProtectedFiles::class)->cleanupUnreferenced($file->storage_path);
        Storage::disk('protected')->assertExists($file->storage_path);
    }

    public function test_key_lookup_survives_application_key_rotation_and_fails_closed(): void
    {
        $this->upload()->assertCreated();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->upload()->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('files', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_busy_write_failures_keep_the_api_envelope_and_retry_after(): void
    {
        $this->mock(\App\Services\WriteTransactions::class)->shouldReceive('run')->once()
            ->andThrow(new \App\Exceptions\WriteConflict('write_busy', 'Another save is using this record.', 503));
        $this->create()->assertStatus(503)->assertJsonPath('code', 'write_busy')->assertHeader('Retry-After', '1')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('idempotency_requests', 0);
        $this->assertDatabaseCount('users', 1);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'username' => 'transaction.staff', 'first_name' => 'Synthetic', 'last_name' => 'Staff',
            'middle_name' => null, 'email' => null, 'staff_code' => null, 'department_id' => null,
            'role_codes' => ['doctor'], 'is_active' => true, 'current_password' => 'Correct-secret-123!',
        ];
    }

    /** @param array<string, mixed> $changes */
    private function create(array $changes = []): TestResponse
    {
        return $this->postCommand('/api/v1/staff-management', array_replace($this->data(), $changes), $this->headers());
    }

    private function upload(string $name = 'synthetic.pdf', string $content = "%PDF-1.4\nSynthetic document\n%%EOF"): TestResponse
    {
        return $this->postCommand('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent($name, $content)], $this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Idempotency-Key' => $this->key];
    }
}
