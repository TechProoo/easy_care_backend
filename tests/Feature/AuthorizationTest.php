<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffIsActive;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    #[DataProvider('ordinaryRoles')]
    public function test_ordinary_roles_can_read_only_their_own_staff_entry(string $role): void
    {
        $viewer = $this->staff($role);
        $other = $this->staff('admin');
        $this->actingAs($viewer)->getJson('/api/v1/staff/'.$viewer->id)
            ->assertOk()->assertJsonPath('data.id', $viewer->id)
            ->assertJsonMissingPath('data.email')->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.permissions')->assertJsonMissingPath('data.roles');
        $denied = $this->getJson('/api/v1/staff/'.$other->id)->assertNotFound()->json();
        $missing = $this->getJson('/api/v1/staff/999999')->assertNotFound()->json();
        $this->assertSame($missing, $denied);
        $this->getJson('/api/v1/staff')->assertForbidden();
        $this->getJson('/api/v1/access-control')->assertForbidden();
        $this->getJson('/api/v1/departments')->assertOk();
    }

    public static function ordinaryRoles(): array
    {
        return [['frontdesk'], ['doctor'], ['nurse'], ['pharmacy'], ['hmo'], ['diagnostics-and-radiologist']];
    }

    public function test_admin_reads_directory_and_catalog_without_an_unconditional_bypass(): void
    {
        $viewer = $this->staff('admin');
        $other = $this->staff('doctor');
        $this->actingAs($viewer)->getJson('/api/v1/staff')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/staff/'.$other->id)->assertOk()->assertJsonPath('data.id', $other->id);
        $this->getJson('/api/v1/access-control')->assertOk()->assertJsonCount(5, 'data.permissions')
            ->assertJsonCount(7, 'data.roles')->assertJsonMissingPath('data.users');

        $viewer->roles()->sole()->permissions()->detach(
            Permission::query()->where('code', 'staff.view_any')->value('id'),
        );
        $this->getJson('/api/v1/staff')->assertForbidden();
        $this->getJson('/api/v1/staff/'.$other->id)->assertNotFound();
    }

    public function test_department_scope_is_applied_before_search_pagination_and_direct_lookup(): void
    {
        $department = Department::factory()->create();
        $viewer = $this->staff('doctor', $department);
        $local = User::factory()->for($department)->create(['first_name' => 'Local', 'last_name' => 'Colleague']);
        $remote = User::factory()->for(Department::factory())->create(['first_name' => 'Remote', 'last_name' => 'Needle']);
        $this->grant('doctor', 'staff.view_department');

        $this->actingAs($viewer)->getJson('/api/v1/staff?per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/staff/'.$local->id)->assertOk();
        $this->getJson('/api/v1/staff/'.$remote->id)->assertNotFound();
        $this->getJson('/api/v1/staff?q=Remote&department_id='.$remote->department_id.'&all=true')
            ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
    }

    public function test_department_permission_with_no_department_cannot_match_unassigned_staff(): void
    {
        $viewer = $this->staff('doctor');
        $other = $this->staff('nurse');
        $this->grant('doctor', 'staff.view_department');
        $this->actingAs($viewer)->getJson('/api/v1/staff')->assertForbidden();
        $this->getJson('/api/v1/staff/'.$other->id)->assertNotFound();
        $this->getJson('/api/v1/staff/'.$viewer->id)->assertOk();
    }

    public function test_explicit_permission_grants_work_independently_of_role_labels(): void
    {
        $viewer = $this->staff('doctor');
        $this->staff('nurse');
        $this->grant('doctor', 'staff.view_any');
        $this->actingAs($viewer)->getJson('/api/v1/staff')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertFalse($viewer->hasPermission('authorization.view'));
    }

    public function test_unassigned_and_unknown_permissions_fail_closed(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->getJson('/api/v1/staff/'.$viewer->id)->assertNotFound();
        $this->getJson('/api/v1/departments')->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.permissions', []);

        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::factory()->create(['code' => 'everything.*']));
        $viewer->roles()->attach($role);
        $this->assertFalse($viewer->hasPermission('everything.*'));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.permissions', []);
        $this->getJson('/api/v1/staff')->assertForbidden();
    }

    public function test_duplicate_grants_are_deduplicated_and_revocation_is_not_cached(): void
    {
        $viewer = $this->staff('doctor');
        $viewer->roles()->attach(Role::query()->where('code', 'nurse')->sole());
        $viewer->load('roles.permissions');
        $this->assertSame(['reference-data.view', 'staff.view_self'], $viewer->permissionCodes()->all());
        Permission::query()->where('code', 'reference-data.view')->sole()->roles()->detach();
        $this->assertFalse($viewer->hasPermission('reference-data.view'));
        $this->assertSame(['staff.view_self'], $viewer->permissionCodes()->all());
    }

    public function test_inactive_accounts_and_guest_requests_cannot_use_directory_endpoints(): void
    {
        $viewer = $this->staff('admin');
        $this->getJson('/api/v1/staff')->assertUnauthorized();
        $this->getJson('/api/v1/staff/'.$viewer->id)->assertUnauthorized();
        $this->getJson('/api/v1/access-control')->assertUnauthorized();
        $viewer->is_active = false;
        $viewer->save();
        $this->assertFalse(Gate::forUser($viewer)->allows('viewAny', User::class));
        $this->assertFalse(User::query()->visibleTo($viewer)->exists());
        $this->actingAs($viewer)->getJson('/api/v1/staff')->assertUnauthorized();
    }

    public function test_permission_is_checked_before_query_validation_and_write_abilities_are_denied(): void
    {
        $viewer = $this->staff('doctor');
        $this->actingAs($viewer)->getJson('/api/v1/staff?per_page=101')->assertForbidden();
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $viewer));
        $this->assertFalse(Gate::forUser($viewer)->allows('delete', $viewer));
        $this->patchJson('/api/v1/staff/'.$viewer->id, ['roles' => ['admin']])->assertStatus(405);
    }

    public function test_directory_queries_are_validated_and_private_fields_are_not_returned(): void
    {
        $viewer = $this->staff('admin');
        $this->actingAs($viewer)->getJson('/api/v1/staff?per_page=101&sort=password')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'sort']);
        $this->getJson('/api/v1/staff?sort=-username&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.permissions')->assertHeader('Cache-Control', 'no-store, private');
        $department = Department::query()->firstOrFail();
        $this->getJson('/api/v1/departments/'.$department->id)->assertOk();
    }

    public function test_every_resource_endpoint_has_server_authentication_and_authorization(): void
    {
        $public = ['api/v1/health', 'api/v1/auth/csrf-token', 'api/v1/auth/login'];
        $session = ['api/v1/auth/me', 'api/v1/auth/logout'];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || in_array($route->uri(), $public, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:web', $middleware, $route->uri());
            $this->assertContains('auth.session', $middleware, $route->uri());
            $this->assertContains(EnsureStaffIsActive::class, $middleware, $route->uri());
            if (! in_array($route->uri(), $session, true)) {
                $this->assertNotEmpty(array_filter($middleware, fn (string $name): bool => str_starts_with($name, 'can:')), $route->uri());
            }
        }
    }

    private function staff(string $role, ?Department $department = null): User
    {
        $user = User::factory()->create(['department_id' => $department?->id]);
        $user->roles()->attach(Role::query()->where('code', $role)->sole());

        return $user;
    }

    private function grant(string $role, string $permission): void
    {
        Role::query()->where('code', $role)->sole()->permissions()->syncWithoutDetaching([
            Permission::query()->where('code', $permission)->value('id'),
        ]);
    }
}
