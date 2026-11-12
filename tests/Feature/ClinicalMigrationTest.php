<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplateVersion;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClinicalMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_clinical_upgrade_and_rollback_preserve_existing_identity_visits_and_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $visit = Encounter::factory()->create();
        DoctorAssignment::factory()->for($visit)->create();
        $migration = require database_path('migrations/2026_11_12_042242_create_clinical_storage_tables.php');
        $tables = ['clinical_templates', 'clinical_template_versions', 'clinical_records', 'vital_signs',
            'clinical_entries', 'diagnoses', 'clinical_procedures', 'prescriptions', 'prescription_items'];
        $this->assertTablesUnchanged(['users', 'roles', 'permissions', 'role_permissions', 'permission_baselines',
            'patients', 'patient_identifiers', 'patient_categories', 'appointments', 'appointment_revisions',
            'appointment_follow_ups', 'encounters', 'doctor_assignments', 'encounter_revisions', 'files', 'audit_events',
            'idempotency_requests'], function () use ($migration, $tables): void {
            $migration->down();
            foreach ($tables as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $migration->up();
            foreach ($tables as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        });
        $version = ClinicalTemplateVersion::factory()->create();
        $entry = ClinicalEntry::factory()->create(['clinical_template_version_id' => $version->id]);
        $entry->record->forceFill(['status' => 'signed', 'version' => 2, 'finalized_by' => $entry->record->authored_by,
            'finalized_at' => now('UTC')])->save();
        $this->assertTrue($entry->record->fresh()->entry->is($entry));
        ClinicalRecord::factory()->amending($entry->record)->create();
        $migration->down();
        $migration->up();
        $this->seed(DatabaseSeeder::class);
        foreach ($tables as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('permissions', 28);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertDatabaseCount('permission_baselines', 4);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
