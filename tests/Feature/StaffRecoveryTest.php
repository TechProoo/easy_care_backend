<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create(['password' => 'Correct-secret-123!']);
        $this->admin->roles()->attach(Role::where('code', 'admin')->sole());
        $this->target = User::factory()->create(['username' => 'recoverable', 'email' => null, 'password' => 'Previous-secret-123!']);
        $this->target->roles()->attach(Role::where('code', 'doctor')->sole());
    }

    public function test_admin_issues_a_hashed_expiring_code_and_staff_set_their_own_password(): void
    {
        $response = $this->issue()->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $code = $response->json('data.reset_code');
        $this->assertSame(48, strlen($code));
        $this->assertDatabaseHas('staff_password_resets', [
            'user_id' => $this->target->id, 'issued_by' => $this->admin->id, 'token_hash' => hash('sha256', $code),
        ]);
        $this->assertNotSame($code, DB::table('staff_password_resets')->sole()->token_hash);
        $this->assertSame(2, $this->target->fresh()->version);
        DB::table('sessions')->insert([
            'id' => 'old-session', 'user_id' => $this->target->id, 'payload' => '', 'last_activity' => now()->timestamp,
        ]);
        $this->getJson('/api/v1/staff-management/'.$this->target->id)->assertOk()->assertJsonMissingPath('data.reset_code');
        $this->postJson('/api/v1/auth/password/reset', $this->resetData($code))->assertNoContent();
        $this->assertTrue(Hash::check('Replacement-secret-123!', $this->target->fresh()->password));
        $this->assertFalse(Hash::check('Previous-secret-123!', $this->target->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->target->id]);
        $this->assertDatabaseCount('staff_password_resets', 0);
        $this->postJson('/api/v1/auth/password/reset', $this->resetData($code))->assertUnprocessable();
    }

    public function test_reset_is_available_without_a_session_and_does_not_sign_staff_in(): void
    {
        $code = $this->issue()->json('data.reset_code');
        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/v1/auth/password/reset', $this->resetData($code))->assertNoContent();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_wrong_expired_inactive_and_unknown_account_codes_have_identical_failures(): void
    {
        $code = $this->issue()->json('data.reset_code');
        $wrong = $this->postJson('/api/v1/auth/password/reset', $this->resetData(str_repeat('0', 48)))
            ->assertUnprocessable()->json();
        $unknown = $this->postJson('/api/v1/auth/password/reset', array_replace($this->resetData($code), ['username' => 'nobody']))
            ->assertUnprocessable()->json();
        $this->assertSame($wrong, $unknown);
        $this->target->is_active = false;
        $this->target->save();
        $inactive = $this->postJson('/api/v1/auth/password/reset', $this->resetData($code))->assertUnprocessable()->json();
        $this->assertSame($wrong, $inactive);
        $this->target->is_active = true;
        $this->target->save();
        $this->travel(15)->minutes();
        $expired = $this->postJson('/api/v1/auth/password/reset', $this->resetData($code))->assertUnprocessable()->json();
        $this->assertSame($wrong, $expired);
        $this->assertTrue(Hash::check('Previous-secret-123!', $this->target->fresh()->password));
    }

    public function test_reissuing_a_code_invalidates_the_previous_code_and_stale_issuance_is_rejected(): void
    {
        $old = $this->issue()->json('data.reset_code');
        $this->issue(1)->assertConflict();
        $new = $this->issue(2)->assertCreated()->json('data.reset_code');
        $this->assertNotSame($old, $new);
        $this->assertDatabaseCount('staff_password_resets', 1);
        $this->postJson('/api/v1/auth/password/reset', $this->resetData($old))->assertUnprocessable();
        $this->postJson('/api/v1/auth/password/reset', $this->resetData($new))->assertNoContent();
    }

    public function test_issuing_requires_its_permission_reauthentication_and_identity_verification(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/staff-management/'.$this->target->id.'/password-reset', [
            'version' => 1, 'current_password' => 'Incorrect-secret-123!', 'identity_verified' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->postJson('/api/v1/staff-management/'.$this->target->id.'/password-reset', [
            'version' => 1, 'current_password' => 'Correct-secret-123!', 'identity_verified' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('identity_verified');
        $this->admin->roles()->sole()->permissions()->detach(Permission::where('code', 'staff.reset_password')->sole());
        $this->issue()->assertForbidden();
        $this->assertDatabaseCount('staff_password_resets', 0);
    }

    public function test_codes_cannot_be_issued_for_self_or_disabled_staff(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/staff-management/'.$this->admin->id.'/password-reset', [
            'version' => 1, 'current_password' => 'Correct-secret-123!', 'identity_verified' => true,
        ])->assertUnprocessable();
        $this->target->is_active = false;
        $this->target->save();
        $this->issue()->assertUnprocessable();
        $this->assertDatabaseCount('staff_password_resets', 0);
    }

    public function test_password_validation_does_not_consume_a_valid_code(): void
    {
        $code = $this->issue()->json('data.reset_code');
        foreach (['short', str_repeat("\u{00e9}", 36).'Aa1', 'onlylowercaseanddigits123'] as $password) {
            $this->postJson('/api/v1/auth/password/reset', array_replace($this->resetData($code), [
                'password' => $password, 'password_confirmation' => $password,
            ]))->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->assertDatabaseCount('staff_password_resets', 1);
        $this->postJson('/api/v1/auth/password/reset', $this->resetData($code))->assertNoContent();
    }

    public function test_recovery_attempts_are_throttled_without_consuming_the_code(): void
    {
        $code = $this->issue()->json('data.reset_code');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/password/reset', $this->resetData(str_repeat('0', 48)))->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/password/reset', $this->resetData($code))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('staff_password_resets', 1);
    }

    public function test_malformed_recovery_identity_is_rejected_as_validation(): void
    {
        $this->postJson('/api/v1/auth/password/reset', ['username' => ['not-a-string']])
            ->assertUnprocessable()->assertJsonValidationErrors(['username', 'token', 'password']);
    }

    public function test_server_console_can_issue_recovery_without_a_second_administrator(): void
    {
        $this->artisan('staff:recover', ['username' => $this->admin->username])
            ->expectsConfirmation('Have you verified this staff member identity using the hospital recovery procedure?', 'yes')
            ->assertSuccessful();
        $this->assertDatabaseHas('staff_password_resets', ['user_id' => $this->admin->id, 'issued_by' => null]);
        $this->assertTrue(Hash::check('Correct-secret-123!', $this->admin->fresh()->password));
    }

    public function test_cancelled_console_recovery_does_not_issue_a_code_or_reactivate_staff(): void
    {
        $this->artisan('staff:recover recoverable')
            ->expectsConfirmation('Have you verified this staff member identity using the hospital recovery procedure?', 'no')
            ->assertFailed();
        $this->target->is_active = false;
        $this->target->save();
        $this->artisan('staff:recover recoverable')->assertFailed();
        $this->assertDatabaseCount('staff_password_resets', 0);
        $this->assertFalse($this->target->fresh()->is_active);
    }

    private function issue(int $version = 1): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/staff-management/'.$this->target->id.'/password-reset', [
            'version' => $version, 'current_password' => 'Correct-secret-123!', 'identity_verified' => true,
        ]);
    }

    /** @return array<string, string> */
    private function resetData(string $code): array
    {
        return [
            'username' => 'recoverable', 'token' => $code,
            'password' => 'Replacement-secret-123!', 'password_confirmation' => 'Replacement-secret-123!',
        ];
    }
}
