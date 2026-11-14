<?php

namespace Tests\Feature;

use App\Models\ClinicalEntry;
use App\Models\ClinicalRevision;
use App\Models\ClinicalTemplateVersion;
use App\Models\Prescription;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClinicalReviewMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_revision_upgrade_and_rollback_preserve_legacy_clinical_content_and_reference_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $version = ClinicalTemplateVersion::factory()->create();
        $record = ClinicalEntry::factory()->create(['clinical_template_version_id' => $version->id, 'content' => 'Retained signed legacy note'])->record;
        $record->forceFill(['status' => 'signed', 'version' => 2, 'finalized_by' => $record->authored_by, 'finalized_at' => now('UTC')])->save();
        Prescription::factory()->withItems()->create();
        $migration = require database_path('migrations/2026_11_12_052540_create_clinical_revision_records.php');
        $tables = ['users', 'patients', 'patient_identifiers', 'roles', 'permissions', 'role_permissions', 'permission_baselines',
            'appointments', 'encounters', 'doctor_assignments', 'clinical_records', 'clinical_entries', 'clinical_templates',
            'clinical_template_versions', 'vital_signs', 'diagnoses', 'clinical_procedures', 'prescriptions', 'prescription_items',
            'audit_events', 'files', 'idempotency_requests'];
        $this->assertTablesUnchanged($tables, function () use ($migration): void {
            $migration->down();
            $this->assertFalse(Schema::hasTable('clinical_revisions'));
            $migration->up();
            $this->assertDatabaseCount('clinical_revisions', 0);
        });
        ClinicalRevision::factory()->create();
        $this->assertDatabaseCount('clinical_revisions', 1);
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('clinical_revisions', 0);
        $this->assertSame('Retained signed legacy note', $record->fresh()->entry->content);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('permissions', 34);
        $this->assertDatabaseCount('role_permissions', 21);
        $this->assertDatabaseCount('permission_baselines', 4);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
