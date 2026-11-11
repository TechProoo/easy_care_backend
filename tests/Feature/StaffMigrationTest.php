<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class StaffMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_users_survive_upgrade_and_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_11_001420_create_staff_foundation_tables.php');
        $migration->down();
        $password = Hash::make('Original-secret-123!');
        $id = DB::table('users')->insertGetId([
            'name' => 'Existing Staff Member',
            'email' => 'existing@example.test',
            'password' => $password,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $migration->up();
        $this->assertDatabaseHas('users', [
            'id' => $id, 'name' => 'Existing Staff Member', 'username' => 'legacy_'.$id,
            'first_name' => 'Existing', 'last_name' => 'Staff Member',
            'email' => 'existing@example.test', 'password' => $password,
        ]);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'username'));
        $this->assertDatabaseHas('users', ['id' => $id, 'name' => 'Existing Staff Member', 'password' => $password]);
        $migration->up();
        $this->assertTrue(Schema::hasTable('role_permissions'));
    }

    public function test_rollback_refuses_to_break_staff_with_no_email(): void
    {
        $user = User::factory()->create(['email' => null]);
        $migration = require database_path('migrations/2026_11_11_001420_create_staff_foundation_tables.php');

        try {
            $migration->down();
            $this->fail('Rollback must stop before removing any tables.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Assign email addresses', $exception->getMessage());
            $this->assertTrue(Schema::hasTable('role_permissions'));
            $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => null]);
        } finally {
            DB::table('users')->where('id', $user->id)->update(['email' => 'rollback@example.test']);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
