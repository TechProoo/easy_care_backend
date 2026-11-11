<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeatureVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeProtectedStorage();
        $this->admin = $this->createStaffWithRole('admin');
    }

    public function test_a_valid_save_persists_relationships_and_has_one_private_audit_event(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postCommand('/api/v1/staff-management', $this->staffData())->assertCreated()->json('data.id');
        $this->getJson('/api/v1/staff-management/'.$id)->assertOk()
            ->assertJsonPath('data.username', 'verification.staff')->assertJsonPath('data.roles.0.code', 'doctor')
            ->assertJsonPath('data.version', 1)->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
        $this->assertDatabaseHas('users', ['id' => $id, 'first_name' => 'Verification-Sentinel']);
        $this->assertDatabaseHas('user_roles', ['user_id' => $id, 'role_id' => Role::where('code', 'doctor')->sole()->id]);
        $this->assertAuditEventRecorded('staff.created', $this->admin->id, 'staff', $id,
            summary: ['role_codes' => ['doctor'], 'version' => 1]);
        $this->assertAuditExcludes([self::STAFF_TEST_PASSWORD, 'Verification-Sentinel', 'verification.staff', 'verification@example.test']);
    }

    public function test_invalid_data_keeps_every_affected_table_unchanged_and_audits_the_denial(): void
    {
        $this->actingAs($this->admin);
        $response = $this->assertTablesUnchanged($this->staffTables(), fn () => $this->postCommand(
            '/api/v1/staff-management', array_replace($this->staffData(), ['first_name' => '', 'role_codes' => ['not-a-role']]),
        ));
        $this->assertApiFailure($response, 422, 'validation_failed', ['first_name', 'role_codes.0']);
        $this->assertAuditEventRecorded('staff.created', $this->admin->id, 'staff', null, 'denied');
        $this->assertNoSuccessfulAudit('staff.created');
    }

    public function test_a_real_audit_insert_failure_rolls_back_the_related_save_and_command(): void
    {
        $this->actingAs($this->admin);
        $triggered = $this->failNextWriteTo('audit_events', 'insert');
        $response = $this->assertTablesUnchanged($this->staffTables(), fn () => $this->postCommand('/api/v1/staff-management', $this->staffData()));
        $this->assertTrue($triggered(), 'The intended audit write must be reached, rather than failing earlier validation.');
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertNoSuccessfulAudit('staff.created');
        $this->assertAuditEventRecorded('staff.created', $this->admin->id, 'staff', null, 'failed');
        $this->assertStringNotContainsString('Synthetic failure', $response->getContent());
    }

    public function test_failure_after_the_profile_update_preserves_roles_sessions_and_recovery_state(): void
    {
        $target = $this->createStaffWithRole('doctor');
        DB::table('sessions')->insert(['id' => 'verification-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('staff_password_resets')->insert(['user_id' => $target->id, 'issued_by' => $this->admin->id,
            'token_hash' => hash('sha256', 'synthetic-reset'), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
        $this->actingAs($this->admin);
        $triggered = $this->failNextWriteTo('users', 'update');
        $data = collect($this->staffData())->except('username')->all();
        $response = $this->assertTablesUnchanged($this->staffTables(), fn () => $this->patchCommand(
            '/api/v1/staff-management/'.$target->id, $data + ['version' => 1],
        ));
        $this->assertTrue($triggered());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertSame(0, DB::table('audit_events')->where('action', 'staff.updated')->where('outcome', 'succeeded')->count());
    }

    public function test_file_completion_failure_cleans_bytes_and_rolls_back_metadata_and_success_audit(): void
    {
        $this->actingAs($this->admin);
        $triggered = $this->failNextWriteTo('idempotency_requests', 'update');
        $response = $this->assertTablesUnchanged(['files', 'idempotency_requests'], fn () => $this->postCommand('/api/v1/files', ['file' => $this->pdf()]));
        $this->assertTrue($triggered(), 'The command completion UPDATE must be reached after storing the file.');
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertSame([], Storage::disk('protected')->allFiles());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'file.uploaded')->where('outcome', 'succeeded')->count());
    }

    public function test_another_owner_is_indistinguishable_from_an_unknown_file_and_cannot_be_spoofed(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postCommand('/api/v1/files', ['file' => $this->pdf('owner-sentinel.pdf')])->assertCreated()->json('data.id');
        $other = $this->createStaffWithRole('doctor');
        $this->grantRolePermissions('doctor', ['files.view_own', 'files.upload']);
        $this->flushSession();
        $this->actingAs($other);
        $this->assertTablesUnchanged(['files', 'idempotency_requests'], function () use ($id): void {
            $hidden = $this->getJson('/api/v1/files/'.$id);
            $missing = $this->getJson('/api/v1/files/99999999');
            $this->assertApiFailure($hidden, 404, 'not_found');
            $this->assertSame($hidden->json(), $missing->assertNotFound()->json());
            $this->assertStringNotContainsString('owner-sentinel', $hidden->getContent());
            $this->getJson('/api/v1/files')->assertOk()->assertJsonCount(0, 'data');
            $response = $this->postCommand('/api/v1/files', ['file' => $this->pdf(), 'uploaded_by' => $this->admin->id]);
            $this->assertApiFailure($response, 422, 'validation_failed', ['uploaded_by']);
        });
        $this->assertCount(1, Storage::disk('protected')->allFiles());
    }

    #[DataProvider('writeAccessCases')]
    public function test_write_access_denials_leave_domain_state_unchanged(?string $role, bool $active, bool $readOnly, int $status, string $code): void
    {
        if ($role !== null) {
            $staff = $this->createStaffWithRole($role, ['is_active' => $active]);
            if ($readOnly) {
                $this->grantRolePermissions($role, ['files.view_own']);
            }
            $this->actingAs($staff);
        }
        $response = $this->assertTablesUnchanged(['users', 'user_roles', 'files', 'idempotency_requests'],
            fn () => $this->postCommand('/api/v1/files', ['file' => $this->pdf()]));
        $this->assertApiFailure($response, $status, $code);
        $this->assertSame([], Storage::disk('protected')->allFiles());
    }

    public function test_inactive_staff_are_rejected_while_logout_revokes_their_remember_token(): void
    {
        $staff = $this->createStaffWithRole('admin', ['is_active' => false]);
        $before = (array) DB::table('users')->where('id', $staff->id)->sole();
        $token = $before['remember_token'];
        unset($before['remember_token']);
        $this->actingAs($staff);
        $response = $this->assertTablesUnchanged(['user_roles', 'files', 'idempotency_requests'],
            fn () => $this->postCommand('/api/v1/files', ['file' => $this->pdf()]));
        $this->assertApiFailure($response, 401, 'unauthenticated');
        $after = (array) DB::table('users')->where('id', $staff->id)->sole();
        $this->assertNotSame($token, $after['remember_token']);
        unset($after['remember_token']);
        $this->assertSame(hash('sha256', json_encode($before)), hash('sha256', json_encode($after)),
            'Inactive staff profile must stay unchanged while logout rotates the remember token.');
    }

    public static function writeAccessCases(): array
    {
        return [
            'guest' => [null, true, false, 401, 'unauthenticated'],
            'role without permission' => ['doctor', true, false, 403, 'forbidden'],
            'read-only staff' => ['doctor', true, true, 403, 'forbidden'],
        ];
    }

    public function test_the_rollback_probe_detects_changed_values_even_when_row_counts_match(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Table users changed');
        $this->assertTablesUnchanged(['users'], fn () => User::whereKey($this->admin->id)->update(['first_name' => 'Changed sentinel']));
    }

    public function test_an_empty_rollback_probe_is_rejected_instead_of_passing_without_evidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->assertTablesUnchanged([], fn () => null);
    }

    public function test_the_privacy_probe_detects_values_escaped_inside_json(): void
    {
        $sentinel = "private-\u{00e9}\\path\"value";
        DB::table('audit_events')->insert([
            'action' => 'auth.login', 'actor_id' => $this->admin->id, 'subject_type' => 'staff', 'subject_id' => $this->admin->id,
            'outcome' => 'succeeded', 'channel' => 'http', 'request_id' => '11111111-1111-4111-8111-111111111111',
            'safe_summary' => json_encode(['unexpected_fixture_value' => $sentinel], JSON_THROW_ON_ERROR), 'occurred_at' => now('UTC'),
        ]);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('An audit field contains a forbidden fixture value.');
        $this->assertAuditExcludes([$sentinel]);
    }

    /** @return list<string> */
    private function staffTables(): array
    {
        return ['users', 'user_roles', 'sessions', 'staff_password_resets', 'idempotency_requests'];
    }

    /** @return array<string, mixed> */
    private function staffData(): array
    {
        return ['username' => 'verification.staff', 'first_name' => 'Verification-Sentinel', 'last_name' => 'Synthetic',
            'middle_name' => null, 'email' => 'verification@example.test', 'staff_code' => null, 'department_id' => null,
            'role_codes' => ['doctor'], 'is_active' => true, 'current_password' => self::STAFF_TEST_PASSWORD];
    }

    private function pdf(string $name = 'verification.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nSynthetic verification fixture\n%%EOF");
    }
}
