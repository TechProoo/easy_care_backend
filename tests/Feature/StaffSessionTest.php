<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffSessionTest extends TestCase
{
    use RefreshDatabase;

    private ?string $cookie = null;

    private ?string $csrf = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
        $this->withCredentials();
        $this->app->bind(PreventRequestForgery::class, fn (Application $app): PreventRequestForgery =>
            new class($app, $app['encrypter']) extends PreventRequestForgery
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
    }

    public function test_csrf_bootstrap_sets_a_private_http_only_session_cookie(): void
    {
        $response = $this->send('GET', '/api/v1/auth/csrf-token')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertIsString($response->json('data.csrf_token'));
        $cookies = collect($response->headers->getCookies());
        $this->assertTrue($cookies->first(fn ($cookie): bool => $cookie->getName() === config('session.cookie'))->isHttpOnly());
    }

    #[DataProvider('loginIdentities')]
    public function test_staff_can_sign_in_by_username_or_email_and_restore_the_session(string $field): void
    {
        $user = $this->staff();
        $this->send('GET', '/api/v1/auth/csrf-token');
        $oldId = DB::table('sessions')->value('id');
        $oldToken = $this->csrf;
        $response = $this->send('POST', '/api/v1/auth/login', [
            'email_or_username' => $user->{$field}, 'password' => 'Correct-secret-123!',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.roles.0.code', 'doctor')
            ->assertJsonPath('data.user.permissions.0', 'reference-data.view');
        $this->assertNotSame($oldToken, $this->csrf);
        $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertArrayNotHasKey('remember_token', $response->json('data.user'));
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->send('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->send('GET', '/api/v1/departments')->assertOk();
    }

    public static function loginIdentities(): array
    {
        return [['username'], ['email']];
    }

    #[DataProvider('failedIdentities')]
    public function test_bad_password_unknown_user_and_disabled_account_have_identical_failures(string $case): void
    {
        $user = $this->staff();
        if ($case === 'disabled') {
            $user->is_active = false;
            $user->save();
        }
        $this->send('GET', '/api/v1/auth/csrf-token');
        $this->send('POST', '/api/v1/auth/login', [
            'email_or_username' => $case === 'unknown' ? 'nobody' : $user->username,
            'password' => $case === 'password' ? 'Incorrect-secret-123!' : 'Correct-secret-123!',
        ])->assertUnprocessable()->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.email_or_username.0', 'The username/email or password is incorrect.');
        $this->send('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->assertNull($user->fresh()->last_login_at);
    }

    public static function failedIdentities(): array
    {
        return [['password'], ['unknown'], ['disabled']];
    }

    public function test_login_requires_a_valid_csrf_token(): void
    {
        $this->staff();
        $this->send('GET', '/api/v1/auth/csrf-token');
        $this->send('POST', '/api/v1/auth/login', [
            'email_or_username' => 'clinician', 'password' => 'Correct-secret-123!',
        ], ['X-CSRF-TOKEN' => 'incorrect'])->assertStatus(419)->assertJsonPath('code', 'session_expired');
        $this->send('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_old_session_and_requires_csrf(): void
    {
        $user = $this->staff();
        $this->login($user);
        $oldCookie = $this->cookie;
        $oldId = DB::table('sessions')->where('user_id', $user->id)->value('id');
        $this->assertNotNull($oldId);
        $this->send('POST', '/api/v1/auth/logout', [], ['X-CSRF-TOKEN' => 'incorrect'])->assertStatus(419);
        $this->send('GET', '/api/v1/auth/me')->assertOk();
        $this->send('POST', '/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
        $this->cookie = $oldCookie;
        $this->send('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_expired_database_sessions_cannot_be_reused(): void
    {
        $user = $this->staff();
        $this->login($user);
        DB::table('sessions')->where('user_id', $user->id)->update([
            'last_activity' => now()->subMinutes(config('session.lifetime') + 1)->timestamp,
        ]);
        $this->send('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_deactivation_revokes_access_and_the_observed_session(): void
    {
        $user = $this->staff();
        $this->login($user);
        $oldCookie = $this->cookie;
        $user->is_active = false;
        $user->save();
        $this->send('GET', '/api/v1/auth/me')->assertUnauthorized();
        $user->is_active = true;
        $user->save();
        $this->cookie = $oldCookie;
        $this->send('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_changes_revoke_existing_session_access(): void
    {
        $user = $this->staff();
        $this->login($user);
        $user->password = 'New-correct-secret-123!';
        $user->save();
        $this->send('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_failed_login_attempts_are_throttled_with_retry_after(): void
    {
        $this->send('GET', '/api/v1/auth/csrf-token');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->send('POST', '/api/v1/auth/login', [
                'email_or_username' => 'nobody', 'password' => 'Incorrect-secret-123!',
            ])->assertUnprocessable();
        }
        $this->send('POST', '/api/v1/auth/login', [
            'email_or_username' => 'nobody', 'password' => 'Incorrect-secret-123!',
        ])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_input_roles_cannot_grant_permissions_or_create_accounts(): void
    {
        $user = User::factory()->create(['username' => 'unassigned', 'password' => 'Correct-secret-123!']);
        $this->send('GET', '/api/v1/auth/csrf-token');
        $this->send('POST', '/api/v1/auth/login', [
            'email_or_username' => $user->username, 'password' => 'Correct-secret-123!',
            'roles' => ['admin'], 'is_active' => true,
        ])->assertOk()->assertJsonPath('data.user.roles', [])->assertJsonPath('data.user.permissions', []);
        $this->send('GET', '/api/v1/departments')->assertForbidden();
        $this->send('POST', '/api/v1/auth/register', ['username' => 'attacker'])->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_validation_and_authenticated_account_switching_are_rejected(): void
    {
        $this->send('GET', '/api/v1/auth/csrf-token');
        $this->send('POST', '/api/v1/auth/login', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['email_or_username', 'password']);
        $user = $this->staff();
        $this->login($user);
        $this->send('POST', '/api/v1/auth/login', [
            'email_or_username' => 'other', 'password' => 'Correct-secret-123!',
        ])->assertStatus(409);
        $this->send('GET', '/api/v1/auth/me')->assertJsonPath('data.user.id', $user->id);
    }

    private function staff(): User
    {
        $user = User::factory()->for(Department::factory())->create([
            'username' => 'clinician', 'password' => 'Correct-secret-123!',
        ]);
        $role = Role::factory()->create(['code' => 'doctor']);
        $role->permissions()->attach(Permission::factory()->create(['code' => 'reference-data.view']));
        $user->roles()->attach($role);

        return $user;
    }

    private function login(User $user): void
    {
        $this->send('GET', '/api/v1/auth/csrf-token');
        $this->send('POST', '/api/v1/auth/login', [
            'email_or_username' => $user->username, 'password' => 'Correct-secret-123!',
        ])->assertOk();
        $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);
    }

    private function send(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->unencryptedCookies = $this->cookie ? [config('session.cookie') => $this->cookie] : [];
        $response = $this->json($method, $uri, $data, array_merge(
            $this->csrf ? ['X-CSRF-TOKEN' => $this->csrf] : [], $headers,
        ));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $this->cookie = $cookie->getValue();
            }
        }
        if ($response->getContent() !== '' && is_string($response->json('data.csrf_token'))) {
            $this->csrf = $response->json('data.csrf_token');
        }

        return $response;
    }
}
