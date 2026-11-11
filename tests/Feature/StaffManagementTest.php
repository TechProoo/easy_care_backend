<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_guests_and_ordinary_staff_cannot_read_or_mutate_administration(): void
    {
        $target = $this->staff('doctor');
        $this->getJson('/api/v1/staff-management')->assertUnauthorized();
        $this->actingAs($target);
        foreach (['', '/options', '/'.$target->id] as $suffix) {
            $this->getJson('/api/v1/staff-management'.$suffix)->assertForbidden();
        }
        $this->postJson('/api/v1/staff-management', [])->assertForbidden();
        $this->patchJson('/api/v1/staff-management/'.$target->id, [])->assertForbidden();
        $this->postJson('/api/v1/staff-management/'.$target->id.'/password-reset', [])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_can_create_an_account_without_a_shared_initial_password(): void
    {
        $admin = $this->staff('admin');
        $department = Department::where('code', 'doctor')->sole();
        $data = $this->data() + ['username' => ' NEW.DOCTOR '];
        $data['email'] = ' New.Doctor@Example.test ';
        $data['department_id'] = $department->id;
        $response = $this->actingAs($admin)->postJson('/api/v1/staff-management', $data)->assertCreated()
            ->assertJsonPath('data.username', 'new.doctor')->assertJsonPath('data.name', 'New Clinician')
            ->assertJsonPath('data.roles.0.code', 'doctor')->assertJsonPath('data.department.id', $department->id)
            ->assertJsonPath('data.version', 1)->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')->assertHeader('Cache-Control', 'no-store, private');
        $created = User::findOrFail($response->json('data.id'));
        $this->assertSame('new.doctor@example.test', $created->email);
        $this->assertFalse(Hash::check('Correct-secret-123!', $created->password));
        $this->assertDatabaseCount('role_permissions', 19);
    }

    public function test_creation_requires_reauthentication_valid_relationships_and_unique_identifiers(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin)->postJson('/api/v1/staff-management', $this->data() + ['username' => $admin->username])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $invalid = array_replace($this->data(), [
            'username' => 'new-staff', 'department_id' => 999999, 'role_codes' => ['doctor', 'doctor', 'missing'],
            'password' => 'Attempted-secret-123!', 'permissions' => ['staff.manage'],
        ]);
        $this->postJson('/api/v1/staff-management', $invalid)->assertUnprocessable()
            ->assertJsonValidationErrors(['department_id', 'role_codes.1', 'role_codes.2', 'password', 'permissions']);
        $this->postJson('/api/v1/staff-management', array_replace($this->data(), [
            'username' => 'new-staff', 'current_password' => 'Incorrect-secret-123!',
        ]))->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 1);
    }

    public function test_updates_persist_profiles_and_assignments_and_revoke_sessions_and_pending_codes(): void
    {
        $admin = $this->staff('admin');
        $target = $this->staff('doctor');
        $this->sessionRow($target);
        DB::table('staff_password_resets')->insert([
            'user_id' => $target->id, 'issued_by' => $admin->id, 'token_hash' => hash('sha256', 'synthetic'),
            'expires_at' => now()->addMinutes(15), 'created_at' => now(),
        ]);
        $data = array_replace($this->data(), [
            'version' => 1, 'first_name' => 'Updated', 'last_name' => 'Nurse',
            'role_codes' => ['nurse'], 'department_id' => Department::where('code', 'nurse')->value('id'),
            'is_active' => false, 'email' => 'updated@example.test',
        ]);
        $this->actingAs($admin)->patchJson('/api/v1/staff-management/'.$target->id, $data)->assertOk()
            ->assertJsonPath('data.name', 'Updated Nurse')->assertJsonPath('data.roles.0.code', 'nurse')
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.version', 2);
        $this->assertNull($target->fresh()->email_verified_at);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('staff_password_resets', ['user_id' => $target->id]);
        $this->getJson('/api/v1/staff-management/'.$target->id)->assertOk()->assertJsonPath('data.name', 'Updated Nurse');
    }

    public function test_stale_updates_are_rejected_without_partial_profile_or_role_changes(): void
    {
        $admin = $this->staff('admin');
        $target = $this->staff('doctor');
        $this->actingAs($admin)->patchJson('/api/v1/staff-management/'.$target->id, $this->data() + ['version' => 1])->assertOk();
        $this->patchJson('/api/v1/staff-management/'.$target->id, array_replace($this->data(), [
            'version' => 1, 'first_name' => 'Stale', 'role_codes' => ['admin'],
        ]))->assertConflict()->assertJsonPath('code', 'conflict');
        $this->assertSame('New', $target->fresh()->first_name);
        $this->assertSame('doctor', $target->roles()->sole()->code);
        $this->assertSame(2, $target->fresh()->version);
    }

    public function test_admin_cannot_change_own_roles_department_or_active_status(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($admin);
        foreach ([
            ['role_codes' => []], ['is_active' => false],
            ['department_id' => Department::where('code', 'nurse')->value('id')],
        ] as $change) {
            $this->patchJson('/api/v1/staff-management/'.$admin->id, array_replace(
                $this->data(), ['version' => 1, 'role_codes' => ['admin']], $change,
            ))->assertUnprocessable();
        }
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame(1, $admin->fresh()->version);
        $this->assertSame('admin', $admin->roles()->sole()->code);
    }

    public function test_a_limited_manager_cannot_assign_or_modify_higher_privileged_roles_even_on_inactive_accounts(): void
    {
        $manager = $this->staff('doctor');
        $manager->roles()->sole()->permissions()->attach(Permission::where('code', 'staff.manage')->sole());
        $powerful = $this->staff('admin');
        $this->actingAs($manager)->postJson('/api/v1/staff-management', array_replace($this->data(), [
            'username' => 'escalated', 'role_codes' => ['admin'],
        ]))->assertForbidden();
        foreach ([true, false] as $active) {
            $powerful->is_active = $active;
            $powerful->save();
            $this->patchJson('/api/v1/staff-management/'.$powerful->id, $this->data() + ['version' => 1])->assertForbidden();
        }
        $options = $this->getJson('/api/v1/staff-management/options')->assertOk()->json('data.roles');
        $this->assertFalse(collect($options)->firstWhere('code', 'admin')['assignable']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_admin_list_is_validated_paginated_and_searches_literal_wildcards(): void
    {
        $admin = $this->staff('admin');
        User::factory()->create(['first_name' => 'Literal%', 'last_name' => 'Needle']);
        User::factory()->create(['first_name' => 'LiteralX', 'last_name' => 'Needle']);
        $this->actingAs($admin)->getJson('/api/v1/staff-management?q=Literal%25&per_page=1&sort=-username')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.first_name', 'Literal%');
        $this->getJson('/api/v1/staff-management?per_page=101&sort=password')->assertUnprocessable();
        $this->getJson('/api/v1/staff-management/options')->assertOk()->assertJsonCount(7, 'data.roles')
            ->assertJsonCount(7, 'data.departments');
        $this->deleteJson('/api/v1/staff-management/'.$admin->id)->assertStatus(405);
    }

    public function test_a_new_baseline_preserves_previously_revoked_read_permissions(): void
    {
        $role = Role::where('code', 'admin')->sole();
        $role->permissions()->detach(Permission::whereIn('code', ['staff.manage', 'staff.reset_password', 'staff.view_any'])->pluck('id'));
        DB::table('permission_baselines')->where('code', 'staff-administration-v1')->delete();
        $this->seed(DatabaseSeeder::class);
        $this->assertTrue($role->permissions()->where('code', 'staff.manage')->exists());
        $this->assertFalse($role->permissions()->where('code', 'staff.view_any')->exists());
        $role->permissions()->detach(Permission::where('code', 'staff.manage')->sole());
        $this->seed(DatabaseSeeder::class);
        $this->assertFalse($role->permissions()->where('code', 'staff.manage')->exists());
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['password' => 'Correct-secret-123!']);
        $user->roles()->attach(Role::where('code', $role)->sole());

        return $user;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'first_name' => 'New', 'last_name' => 'Clinician', 'middle_name' => null,
            'email' => null, 'staff_code' => null, 'department_id' => null,
            'role_codes' => ['doctor'], 'is_active' => true, 'current_password' => 'Correct-secret-123!',
        ];
    }

    private function sessionRow(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => 'synthetic-session', 'user_id' => $user->id,
            'payload' => '', 'last_activity' => now()->timestamp,
        ]);
    }
}
