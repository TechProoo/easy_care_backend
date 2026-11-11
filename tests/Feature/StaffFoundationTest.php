<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StaffFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_relationships_work_in_both_directions(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->for($department)->create();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create(['code' => 'reference-data.view']);
        $user->roles()->attach($role);
        $role->permissions()->attach($permission);

        $this->assertTrue($user->department->is($department));
        $this->assertTrue($department->users->contains($user));
        $this->assertTrue($user->roles->contains($role));
        $this->assertTrue($role->users->contains($user));
        $this->assertTrue($permission->roles->contains($role));
        $this->assertTrue(Gate::forUser($user)->allows('reference-data.view'));
        $this->assertNotNull($user->roles->first()->pivot->created_at);
    }

    public function test_access_requires_an_explicit_permission_and_active_staff(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create(['code' => 'admin']);
        $user->roles()->attach($role);
        $this->assertFalse($user->hasPermission('reference-data.view'));

        $role->permissions()->attach(Permission::factory()->create(['code' => 'reference-data.view']));
        $this->assertTrue($user->hasPermission('reference-data.view'));
        $user->is_active = false;
        $user->save();
        $this->assertFalse(Gate::forUser($user)->allows('reference-data.view'));
    }

    public function test_seeding_is_repeatable_preserves_edits_and_creates_no_staff_assignments(): void
    {
        $this->seed(DatabaseSeeder::class);
        Department::query()->where('code', 'nurse')->update(['name' => 'Local Nursing Department']);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('departments', 7);
        $this->assertDatabaseCount('roles', 7);
        $this->assertDatabaseCount('permissions', 19);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertDatabaseCount('permission_baselines', 4);
        $this->assertDatabaseHas('departments', ['code' => 'nurse', 'name' => 'Local Nursing Department']);
    }

    public function test_passwords_are_hashed_and_private_attributes_hidden(): void
    {
        $user = User::factory()->create(['password' => 'Example-secret-123!']);
        $this->assertTrue(Hash::check('Example-secret-123!', $user->password));
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
        $this->assertSame(trim($user->first_name.' '.$user->last_name), $user->name);
    }

    public function test_staff_without_email_addresses_can_have_distinct_usernames(): void
    {
        User::factory()->count(2)->create(['email' => null]);
        $this->assertDatabaseCount('users', 2);
    }

    #[DataProvider('duplicateCatalogs')]
    public function test_catalog_codes_are_unique(string $model): void
    {
        $record = $model::factory()->create();
        $this->expectException(QueryException::class);
        $model::factory()->create(['code' => $record->code]);
    }

    public static function duplicateCatalogs(): array
    {
        return [[Department::class], [Role::class], [Permission::class]];
    }

    #[DataProvider('uniqueStaffFields')]
    public function test_staff_identifiers_are_unique(string $field): void
    {
        $user = User::factory()->create(['staff_code' => 'STAFF-001']);
        $this->expectException(QueryException::class);
        User::factory()->create([$field => $user->{$field}]);
    }

    public static function uniqueStaffFields(): array
    {
        return [['username'], ['email'], ['staff_code']];
    }

    public function test_staff_cannot_reference_a_missing_department(): void
    {
        $this->expectException(QueryException::class);
        User::factory()->create(['department_id' => 999999]);
    }

    public function test_a_department_with_staff_cannot_be_deleted(): void
    {
        $department = Department::factory()->create();
        User::factory()->for($department)->create();
        $this->expectException(QueryException::class);
        $department->delete();
    }

    public function test_roles_cannot_reference_missing_permissions(): void
    {
        $role = Role::factory()->create();
        $this->expectException(QueryException::class);
        $role->permissions()->attach(999999);
    }

    public function test_staff_cannot_reference_missing_roles(): void
    {
        $user = User::factory()->create();
        $this->expectException(QueryException::class);
        $user->roles()->attach(999999);
    }

    public function test_duplicate_role_assignments_are_rejected(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $user->roles()->attach($role);
        $this->expectException(QueryException::class);
        $user->roles()->attach($role);
    }

    public function test_deleting_a_role_removes_only_its_pivots(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $permission = Permission::factory()->create();
        $user->roles()->attach($role);
        $role->permissions()->attach($permission);
        $role->delete();
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('role_permissions', 0);
        $this->assertModelExists($user);
        $this->assertModelExists($permission);
    }

    public function test_failed_multi_record_work_rolls_back(): void
    {
        try {
            DB::transaction(function (): void {
                Department::factory()->create(['code' => 'ROLLBACK']);
                Role::factory()->create(['code' => 'rollback']);
                throw new RuntimeException('Simulated failure');
            });
            $this->fail('The transaction should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure', $exception->getMessage());
        }
        $this->assertDatabaseMissing('departments', ['code' => 'ROLLBACK']);
        $this->assertDatabaseMissing('roles', ['code' => 'rollback']);
    }

    public function test_mysql_rejects_nonpositive_staff_versions(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The MySQL suite verifies the version CHECK constraint.');
        }
        $this->expectException(QueryException::class);
        User::factory()->create(['version' => 0]);
    }
}
