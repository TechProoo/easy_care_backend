<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PatientMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_patient_foundation_rolls_back_and_reapplies_without_changing_existing_staff_or_files(): void
    {
        $this->seed(DatabaseSeeder::class);
        $staff = User::factory()->create();
        $migration = require database_path('migrations/2026_11_11_171342_create_patient_foundation_tables.php');
        $lookup = require database_path('migrations/2026_11_11_173615_add_patient_lookup_indexes.php');
        $records = require database_path('migrations/2026_11_11_183300_create_patient_record_management_tables.php');
        $visits = require database_path('migrations/2026_11_11_225842_create_visit_foundation_tables.php');
        $this->assertTablesUnchanged(['users', 'roles', 'permissions', 'role_permissions', 'files', 'audit_events'], function () use ($migration, $lookup, $records, $visits): void {
            $visits->down();
            $records->down();
            $lookup->down();
            $migration->down();
            foreach (['patient_categories', 'patients', 'patient_identifiers', 'patient_contacts', 'patient_relationships'] as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $migration->up();
            $lookup->up();
            $records->up();
            $visits->up();
            $this->seed(DatabaseSeeder::class);
        });
        $patient = Patient::factory()->create(['created_by' => $staff->id]);
        $this->assertDatabaseHas('patient_identifiers', ['patient_id' => $patient->id, 'identifier' => $patient->mrn]);
        $this->assertDatabaseHas('users', ['id' => $staff->id, 'username' => $staff->username]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
