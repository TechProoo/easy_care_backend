<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VisitMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_visit_storage_rolls_back_and_reapplies_without_changing_existing_records_or_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $staff = User::factory()->create();
        $patient = Patient::factory()->create(['created_by' => $staff->id]);
        $migration = require database_path('migrations/2026_11_11_225842_create_visit_foundation_tables.php');
        $bookings = require database_path('migrations/2026_11_12_012918_create_appointment_booking_records.php');
        $workflow = require database_path('migrations/2026_11_12_023554_create_encounter_workflow_records.php');
        $clinical = require database_path('migrations/2026_11_12_042242_create_clinical_storage_tables.php');
        $review = require database_path('migrations/2026_11_12_052540_create_clinical_revision_records.php');
        $this->assertTablesUnchanged(['users', 'roles', 'permissions', 'role_permissions', 'patients', 'patient_identifiers',
            'patient_categories', 'files', 'audit_events', 'idempotency_requests'], function () use ($migration, $bookings, $workflow, $clinical, $review): void {
            $review->down();
            $clinical->down();
            $workflow->down();
            $bookings->down();
            $migration->down();
            foreach (['appointments', 'encounters', 'doctor_assignments'] as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $migration->up();
            $bookings->up();
            $workflow->up();
            $clinical->up();
            $review->up();
            foreach (['appointments', 'encounters', 'doctor_assignments'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        });
        $booking = Appointment::factory()->registered($patient)->create(['booked_by' => $staff->id]);
        $booking->forceFill(['status' => 'attended', 'version' => 2])->save();
        $visit = Encounter::factory()->fromAppointment($booking)->create(['opened_by' => $staff->id]);
        $assignment = DoctorAssignment::factory()->for($visit)->create(['doctor_id' => $staff->id, 'assigned_by' => $staff->id]);
        $this->assertTrue($visit->currentAssignment->is($assignment));
        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'mrn' => $patient->mrn]);
        $this->assertDatabaseCount('permissions', 34);
        $this->assertDatabaseCount('role_permissions', 21);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
