<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_grants_are_minimal_and_do_not_create_or_assign_staff(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (Role::all() as $role) {
            $expected = $role->code === 'admin'
                ? ['audit.view', 'authorization.view', 'files.upload', 'files.view_own', 'reference-data.view', 'staff.manage', 'staff.reset_password', 'staff.view_any', 'staff.view_self']
                : ['reference-data.view', 'staff.view_self'];
            $this->assertSame($expected, $role->permissions()->orderBy('code')->pluck('code')->all());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertDatabaseCount('permission_baselines', 4);
    }

    public function test_grants_and_revocations_are_idempotent_and_apply_to_existing_staff(): void
    {
        $this->seed(DatabaseSeeder::class);
        $role = Role::where('code', 'doctor')->sole();
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->load('roles.permissions');
        $this->assertFalse($user->hasPermission('staff.view_any'));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->artisan('access:role', ['role' => 'doctor', 'permission' => 'staff.view_any'])
                ->expectsOutput('Permission granted.')->assertSuccessful();
        }

        $this->assertTrue($user->hasPermission('staff.view_any'));
        $this->assertDatabaseCount('role_permissions', 22);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->artisan('access:role', ['role' => 'doctor', 'permission' => 'staff.view_any', '--revoke' => true])
                ->expectsOutput('Permission revoked.')->assertSuccessful();
        }

        $this->assertFalse($user->hasPermission('staff.view_any'));
        $this->assertDatabaseCount('role_permissions', 21);
    }

    public function test_reseeding_preserves_revocations_custom_grants_and_edited_labels(): void
    {
        $this->seed(DatabaseSeeder::class);
        $role = Role::where('code', 'admin')->sole();
        $role->update(['name' => 'Hospital administration']);
        $permission = Permission::where('code', 'staff.view_any')->sole();
        $permission->update(['name' => 'Approved directory access']);
        $role->permissions()->detach($permission);
        $doctor = Role::where('code', 'doctor')->sole();
        $doctor->permissions()->attach(Permission::where('code', 'staff.view_department')->sole());

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertFalse($role->permissions()->where('code', 'staff.view_any')->exists());
        $this->assertTrue($doctor->permissions()->where('code', 'staff.view_department')->exists());
        $this->assertSame('Hospital administration', $role->fresh()->name);
        $this->assertSame('Approved directory access', $permission->fresh()->name);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertDatabaseCount('permission_baselines', 4);
        $this->assertDatabaseCount('permissions', 19);
    }

    public function test_unknown_roles_permissions_and_incomplete_revocations_fail_without_changes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->artisan('access:role', ['role' => 'missing', 'permission' => 'staff.view_any'])->assertFailed();
        $this->artisan('access:role', ['role' => 'doctor', 'permission' => '*'])->assertFailed();
        $this->artisan('access:role', ['role' => 'doctor', '--revoke' => true])->assertFailed();
        $this->assertDatabaseCount('role_permissions', 21);

        Permission::where('code', 'staff.view_department')->sole()->delete();
        $this->artisan('access:role', ['role' => 'doctor', 'permission' => 'staff.view_department'])
            ->expectsOutput('The permission catalog is not seeded.')->assertFailed();
        $this->assertDatabaseCount('role_permissions', 21);
    }

    public function test_inspecting_role_permissions_does_not_change_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $this->artisan('access:role', ['role' => 'doctor'])->assertSuccessful();
        $this->assertSame($before, DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }

    public function test_failed_initialization_rolls_back_the_catalog_grants_and_marker(): void
    {
        Role::factory()->create(['code' => 'admin']);

        try {
            $this->seed(AuthorizationSeeder::class);
            $this->fail('Missing required roles must prevent baseline initialization.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('permissions', 0);
            $this->assertDatabaseCount('role_permissions', 0);
            $this->assertDatabaseCount('permission_baselines', 0);
        }
    }
}
