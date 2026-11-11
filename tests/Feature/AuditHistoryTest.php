<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\StaffAdministration;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuditHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_staff_changes_capture_actor_subject_and_access_changes_without_copying_personal_values(): void
    {
        $admin = $this->staff('admin');
        $created = $this->actingAs($admin)->postJson('/api/v1/staff-management', $this->data())
            ->assertCreated()->json('data.id');
        $event = DB::table('audit_events')->where('action', 'staff.created')->sole();
        $this->assertSame($admin->id, (int) $event->actor_id);
        $this->assertSame($created, (int) $event->subject_id);
        $this->assertSame('succeeded', $event->outcome);
        $this->assertSame('http', $event->channel);
        $this->assertNotEmpty($event->request_id);
        $this->assertNotEmpty($event->occurred_at);
        $this->assertSame(['doctor'], json_decode($event->safe_summary, true)['role_codes']);

        $this->patchJson('/api/v1/staff-management/'.$created, array_replace(collect($this->data())->except('username')->all(), [
            'version' => 1, 'first_name' => 'Changed Personal Name', 'role_codes' => ['nurse'], 'is_active' => false,
        ]))->assertOk();
        $summary = json_decode(DB::table('audit_events')->where('action', 'staff.updated')->sole()->safe_summary, true);
        $this->assertContains('first_name', $summary['changed_fields']);
        $this->assertContains('role_codes', $summary['changed_fields']);
        $this->assertSame(['doctor'], $summary['previous_role_codes']);
        $this->assertSame(['nurse'], $summary['role_codes']);
        $this->assertTrue($summary['previous_is_active']);
        $this->assertFalse($summary['is_active']);
        $this->assertTrue($summary['sessions_revoked']);
        $this->assertSame(2, $summary['version']);
        $history = DB::table('audit_events')->get()->toJson();
        foreach (['Sensitive Personal Name', 'Changed Personal Name', 'private@example.test', 'private.staff', 'Correct-secret-123!'] as $secret) {
            $this->assertStringNotContainsString($secret, $history);
        }
    }

    public function test_recovery_issuance_and_password_change_have_separate_events_without_codes_or_passwords(): void
    {
        $admin = $this->staff('admin');
        $target = $this->staff('doctor');
        $code = $this->actingAs($admin)->postJson('/api/v1/staff-management/'.$target->id.'/password-reset', [
            'current_password' => 'Correct-secret-123!', 'version' => 1, 'identity_verified' => true,
        ])->assertCreated()->json('data.reset_code');
        $this->postJson('/api/v1/auth/password/reset', [
            'username' => $target->username, 'token' => $code,
            'password' => 'New-private-secret-987!', 'password_confirmation' => 'New-private-secret-987!',
        ])->assertNoContent();
        $issued = DB::table('audit_events')->where('action', 'staff.recovery_issued')->sole();
        $reset = DB::table('audit_events')->where('action', 'staff.password_reset')->sole();
        $this->assertSame($admin->id, (int) $issued->actor_id);
        $this->assertNull($reset->actor_id);
        $this->assertSame($target->id, (int) $reset->subject_id);
        $this->assertTrue(Hash::check('New-private-secret-987!', $target->fresh()->password));
        $history = DB::table('audit_events')->get()->toJson();
        foreach ([$code, hash('sha256', $code), 'New-private-secret-987!', $target->fresh()->password] as $secret) {
            $this->assertStringNotContainsString($secret, $history);
        }
    }

    public function test_failed_authorization_validation_conflicts_and_recovery_are_audited_without_request_input(): void
    {
        $doctor = $this->staff('doctor');
        $this->actingAs($doctor)->postJson('/api/v1/staff-management', [
            'password' => 'Submitted-private-secret',
        ])->assertForbidden();
        $denied = DB::table('audit_events')->where('action', 'staff.created')->sole();
        $this->assertSame(403, (int) $denied->http_status);
        $this->assertSame($doctor->id, (int) $denied->actor_id);
        $this->assertSame('denied', $denied->outcome);

        $admin = $this->staff('admin');
        $this->flushSession();
        $this->actingAs($admin)->postJson('/api/v1/staff-management', [])->assertUnprocessable();
        $this->patchJson('/api/v1/staff-management/'.$doctor->id, collect($this->data())->except('username')->all() + ['version' => 99])->assertConflict();
        $conflict = DB::table('audit_events')->where('action', 'staff.updated')->sole();
        $this->assertSame($doctor->id, (int) $conflict->subject_id);
        $this->assertSame(409, (int) $conflict->http_status);
        $this->postJson('/api/v1/auth/password/reset', [
            'username' => $doctor->username, 'token' => str_repeat('a', 48),
            'password' => 'Private-new-secret-987!', 'password_confirmation' => 'Private-new-secret-987!',
        ])->assertUnprocessable();
        $history = DB::table('audit_events')->get()->toJson();
        $this->assertStringNotContainsString('Submitted-private-secret', $history);
        $this->assertStringNotContainsString($doctor->username, $history);
        $this->assertStringNotContainsString(str_repeat('a', 48), $history);
        $this->assertSame(1, $doctor->fresh()->version);
    }

    public function test_login_success_failure_and_logout_are_recorded_without_submitted_identity(): void
    {
        $user = $this->staff('doctor');
        $this->postJson('/api/v1/auth/login', [
            'email_or_username' => 'unknown.private@example.test', 'password' => 'Wrong-private-secret',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login', 'actor_id' => null, 'subject_id' => null,
            'outcome' => 'denied', 'http_status' => 422]);
        $this->postJson('/api/v1/auth/login', [
            'email_or_username' => $user->username, 'password' => 'Correct-secret-123!',
        ])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login', 'actor_id' => $user->id,
            'subject_id' => $user->id, 'outcome' => 'succeeded']);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.logout', 'actor_id' => $user->id,
            'subject_id' => $user->id, 'outcome' => 'succeeded']);
        $history = DB::table('audit_events')->get()->toJson();
        $this->assertStringNotContainsString('unknown.private@example.test', $history);
        $this->assertStringNotContainsString('Wrong-private-secret', $history);
        $this->assertStringNotContainsString('Correct-secret-123!', $history);
    }

    public function test_login_throttling_is_audited_and_csrf_bootstrap_is_not_logged(): void
    {
        $this->getJson('/api/v1/auth/csrf-token')->assertOk();
        $this->assertDatabaseCount('audit_events', 0);
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email_or_username' => 'missing', 'password' => 'Wrong-private-secret',
            ]);
        }
        $response->assertTooManyRequests();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login', 'outcome' => 'denied', 'http_status' => 429]);
    }

    public function test_audit_read_requires_its_own_permission_and_respects_revocations_and_inactive_accounts(): void
    {
        $this->getJson('/api/v1/audit-events')->assertUnauthorized();
        $admin = $this->staff('admin');
        $this->actingAs($admin)->getJson('/api/v1/audit-events')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        Role::where('code', 'admin')->sole()->permissions()->detach(Permission::where('code', 'audit.view')->sole());
        $this->getJson('/api/v1/audit-events')->assertForbidden();
        $this->seed(DatabaseSeeder::class);
        $this->getJson('/api/v1/audit-events')->assertForbidden();
        $doctor = $this->staff('doctor');
        $this->flushSession();
        $this->actingAs($doctor)->getJson('/api/v1/audit-events')->assertForbidden();
        Role::where('code', 'doctor')->sole()->permissions()->attach(Permission::where('code', 'audit.view')->sole());
        $this->getJson('/api/v1/audit-events')->assertOk();
        $doctor->forceFill(['is_active' => false])->save();
        $this->actingAs($doctor)->getJson('/api/v1/audit-events')->assertUnauthorized();
        $this->assertDatabaseHas('audit_events', ['action' => 'audit.viewed', 'outcome' => 'denied', 'http_status' => 403]);
    }

    public function test_audit_search_is_paginated_filtered_and_utc_dated_with_stable_order(): void
    {
        $admin = $this->staff('admin');
        $audit = app(AuditTrail::class);
        $this->travelTo(now()->setDate(2026, 11, 10)->startOfDay());
        $audit->record('staff.updated', $admin->id, 'staff', 23, ['version' => 2]);
        $first = DB::table('audit_events')->max('id');
        $audit->record('staff.updated', $admin->id, 'staff', 23, ['version' => 3]);
        $second = DB::table('audit_events')->max('id');
        $audit->record('staff.updated', null, 'staff', 23, ['version' => 4], channel: 'console');
        $this->travel(1)->days();
        $audit->record('staff.updated', $admin->id, 'staff', 23, ['version' => 5]);
        $query = '/api/v1/audit-events?action=staff.updated&outcome=succeeded&channel=http&actor_id='.$admin->id
            .'&subject_type=staff&subject_id=23&from=2026-11-10&to=2026-11-10&per_page=1';
        $this->actingAs($admin)->getJson($query)->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', (int) $second)->assertJsonPath('data.0.safe_summary.version', 3)
            ->assertJsonPath('data.0.occurred_at', '2026-11-10T00:00:00.000000Z');
        $this->getJson($query.'&page=2')->assertOk()->assertJsonPath('data.0.id', (int) $first);
        $this->getJson('/api/v1/audit-events?subject_type=staff&to=2026-11-10')->assertOk()
            ->assertJsonPath('meta.total', 3);
        $this->assertDatabaseHas('audit_events', ['action' => 'audit.viewed', 'actor_id' => $admin->id, 'outcome' => 'succeeded']);
        $this->travelBack();
    }

    public function test_pagination_keeps_a_fixed_history_boundary_while_reads_and_new_actions_are_logged(): void
    {
        $admin = $this->staff('admin');
        $audit = app(AuditTrail::class);
        for ($event = 0; $event < 3; $event++) {
            $audit->record('auth.login', $admin->id, 'staff', $admin->id);
        }
        $response = $this->actingAs($admin)->getJson('/api/v1/audit-events?per_page=2')->assertOk();
        $boundary = $response->json('through_id');
        $firstIds = array_column($response->json('data'), 'id');
        $audit->record('auth.logout', $admin->id, 'staff', $admin->id);
        $next = $this->getJson('/api/v1/audit-events?per_page=2&page=2&through_id='.$boundary)
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data');
        $this->assertNotContains($next->json('data.0.id'), $firstIds);
        $this->assertLessThanOrEqual($boundary, $next->json('data.0.id'));
        $this->assertStringContainsString('through_id='.$boundary, $response->json('links.next'));
    }

    public function test_audit_filter_validation_and_read_only_routes(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin)->getJson('/api/v1/audit-events?action=anything&outcome=other&channel=x&per_page=101&page=0&from=bad&subject_id=1')
            ->assertUnprocessable()->assertJsonValidationErrors(['action', 'outcome', 'channel', 'per_page', 'page', 'from', 'subject_type']);
        $this->getJson('/api/v1/audit-events?from=2026-11-11&to=2026-11-10')
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/audit-events?sort=password')->assertOk();
        foreach (['post', 'patch', 'delete'] as $method) {
            $this->{$method.'Json'}('/api/v1/audit-events')->assertStatus(405);
        }
    }

    #[DataProvider('mutationOperations')]
    public function test_database_rejects_audit_updates_and_deletes(string $operation): void
    {
        app(AuditTrail::class)->record('auth.login', null, 'staff');
        try {
            $query = DB::table('audit_events');
            if ($operation === 'update') {
                $query->update(['outcome' => 'denied']);
            } else {
                $query->delete();
            }
            $this->fail('Audit history must remain append-only.');
        } catch (QueryException) {
            $this->assertDatabaseCount('audit_events', 1);
            $this->assertDatabaseHas('audit_events', ['outcome' => 'succeeded']);
        }
    }

    public static function mutationOperations(): array
    {
        return [['update'], ['delete']];
    }

    public function test_audit_records_survive_staff_deletion_with_the_original_numeric_references(): void
    {
        $user = $this->staff('doctor');
        app(AuditTrail::class)->record('auth.login', $user->id, 'staff', $user->id);
        $id = $user->id;
        $user->delete();
        $this->assertDatabaseHas('audit_events', ['actor_id' => $id, 'subject_id' => $id]);
    }

    #[DataProvider('unsafeSummaries')]
    public function test_audit_writer_rejects_unapproved_values_before_inserting(array $summary): void
    {
        try {
            app(AuditTrail::class)->record('staff.updated', null, 'staff', 1, $summary);
            $this->fail('Unsafe values must never be recorded.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public static function unsafeSummaries(): array
    {
        return [[['password' => 'private']], [['changed_fields' => ['password']]],
            [['role_codes' => ['private@example.test']]], [['is_active' => 'private']],
            [['changed_fields' => [['password']]]]];
    }

    #[DataProvider('staffOperations')]
    public function test_staff_operations_roll_back_when_their_audit_write_fails(string $operation): void
    {
        $admin = $this->staff('admin');
        $target = $this->staff('doctor');
        $service = app(StaffAdministration::class);
        $reset = $service->issueConsoleReset($target);
        $before = $target->fresh()->getAttributes();
        $beforeCode = DB::table('staff_password_resets')->where('user_id', $target->id)->value('token_hash');
        $beforeCount = DB::table('audit_events')->count();
        $this->mock(AuditTrail::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $service = app(StaffAdministration::class);
        try {
            match ($operation) {
                'create' => $service->create($admin, $this->data()),
                'update' => $service->update($admin, $target, $this->data() + ['version' => $target->fresh()->version]),
                'issue' => $service->issueReset($admin, $target, ['current_password' => 'Correct-secret-123!', 'version' => $target->fresh()->version]),
                'console' => $service->issueConsoleReset($target),
                'reset' => $service->resetPassword(['username' => $target->username, 'token' => $reset['token'], 'password' => 'New-private-secret-987!']),
            };
            $this->fail('An unaudited staff change must not commit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 2);
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertSame(['doctor'], $target->roles()->pluck('code')->all());
        $this->assertSame($beforeCode, DB::table('staff_password_resets')->where('user_id', $target->id)->value('token_hash'));
        $this->assertDatabaseCount('audit_events', $beforeCount);
    }

    public static function staffOperations(): array
    {
        return [['create'], ['update'], ['issue'], ['console'], ['reset']];
    }

    public function test_role_commands_record_grants_revocations_noops_and_rejected_codes(): void
    {
        $role = Role::where('code', 'doctor')->sole();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->artisan('access:role', ['role' => 'doctor', 'permission' => 'audit.view'])->assertSuccessful();
        }
        $events = DB::table('audit_events')->orderBy('id')->get();
        $this->assertSame([true, false], $events->map(fn ($event): bool => json_decode($event->safe_summary, true)['changed'])->all());
        $this->artisan('access:role', ['role' => 'doctor', 'permission' => 'audit.view', '--revoke' => true])->assertSuccessful();
        $this->assertDatabaseHas('audit_events', ['action' => 'role.permission_revoked', 'channel' => 'console', 'actor_id' => null, 'subject_id' => $role->id]);
        $this->artisan('access:role', ['role' => 'doctor', 'permission' => 'Unknown-private-secret'])->assertFailed();
        $this->assertDatabaseHas('audit_events', ['action' => 'role.permission_granted', 'outcome' => 'denied']);
        $this->assertStringNotContainsString('Unknown-private-secret', DB::table('audit_events')->get()->toJson());
    }

    public function test_role_grant_rolls_back_when_audit_storage_fails(): void
    {
        $this->mock(AuditTrail::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            $this->artisan('access:role', ['role' => 'doctor', 'permission' => 'audit.view'])->run();
            $this->fail('An unaudited permission grant must not commit.');
        } catch (RuntimeException) {
            $this->assertFalse(Role::where('code', 'doctor')->sole()->permissions()->where('code', 'audit.view')->exists());
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public function test_console_bootstrap_and_cancelled_recovery_use_explicit_console_attribution(): void
    {
        $this->artisan('staff:create private.staff --role=doctor')
            ->expectsQuestion('First name', 'Sensitive Personal Name')->expectsQuestion('Last name', 'Private')
            ->expectsQuestion('Email (optional)', 'private@example.test')
            ->expectsQuestion('Password (at least 12 characters)', 'Correct-secret-123!')
            ->expectsQuestion('Confirm password', 'Correct-secret-123!')->assertSuccessful();
        $target = User::where('username', 'private.staff')->sole();
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.created', 'channel' => 'console', 'actor_id' => null, 'subject_id' => $target->id]);
        $this->artisan('staff:recover', ['username' => $target->username])
            ->expectsConfirmation('Have you verified this staff member identity using the hospital recovery procedure?', 'no')
            ->assertFailed();
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.recovery_issued', 'channel' => 'console', 'outcome' => 'denied']);
        app(StaffAdministration::class)->issueConsoleReset($target);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.recovery_issued', 'channel' => 'console', 'outcome' => 'succeeded']);
        $history = DB::table('audit_events')->get()->toJson();
        $this->assertStringNotContainsString('private@example.test', $history);
        $this->assertStringNotContainsString('Correct-secret-123!', $history);
    }

    public function test_console_account_creation_rolls_back_if_audit_storage_fails(): void
    {
        $this->mock(AuditTrail::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        try {
            $this->artisan('staff:create private.staff --role=doctor')
                ->expectsQuestion('First name', 'Private')->expectsQuestion('Last name', 'Staff')
                ->expectsQuestion('Email (optional)', null)
                ->expectsQuestion('Password (at least 12 characters)', 'Correct-secret-123!')
                ->expectsQuestion('Confirm password', 'Correct-secret-123!')->run();
            $this->fail('An unaudited bootstrap account must not commit.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('user_roles', 0);
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public function test_failed_audit_on_login_cannot_leave_an_authenticated_session(): void
    {
        $user = $this->staff('doctor');
        $this->mock(AuditTrail::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/v1/auth/login', ['email_or_username' => $user->username, 'password' => 'Correct-secret-123!']);
            $this->fail('Login must fail when its audit cannot be saved.');
        } catch (RuntimeException) {
            $this->assertFalse(Auth::guard('web')->check());
            $this->assertNull($user->fresh()->last_login_at);
        }
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['password' => 'Correct-secret-123!']);
        $user->roles()->attach(Role::where('code', $role)->sole());

        return $user;
    }

    private function data(): array
    {
        return ['username' => 'private.staff', 'first_name' => 'Sensitive Personal Name', 'last_name' => 'Private',
            'middle_name' => null, 'email' => 'private@example.test', 'staff_code' => null, 'department_id' => null,
            'is_active' => true, 'role_codes' => ['doctor'], 'current_password' => 'Correct-secret-123!'];
    }
}
