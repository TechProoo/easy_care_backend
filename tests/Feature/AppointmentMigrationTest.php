<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentFollowUp;
use App\Models\AppointmentRevision;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppointmentMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_booking_history_upgrade_and_rollback_preserve_existing_bookings_patients_and_grants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $booking = Appointment::factory()->create();
        $migration = require database_path('migrations/2026_11_12_012918_create_appointment_booking_records.php');
        $workflow = require database_path('migrations/2026_11_12_023554_create_encounter_workflow_records.php');
        $clinical = require database_path('migrations/2026_11_12_042242_create_clinical_storage_tables.php');
        $review = require database_path('migrations/2026_11_12_052540_create_clinical_revision_records.php');
        $this->assertTablesUnchanged(['users', 'roles', 'permissions', 'role_permissions', 'patients', 'patient_identifiers',
            'appointments', 'encounters', 'doctor_assignments', 'audit_events', 'idempotency_requests'], function () use ($migration, $workflow, $clinical, $review): void {
            $review->down();
            $clinical->down();
            $workflow->down();
            $migration->down();
            $this->assertFalse(Schema::hasTable('appointment_revisions'));
            $this->assertFalse(Schema::hasTable('appointment_follow_ups'));
            $migration->up();
            $workflow->up();
            $clinical->up();
            $review->up();
            $this->assertDatabaseCount('appointment_revisions', 0);
            $this->assertDatabaseCount('appointment_follow_ups', 0);
        });
        $this->assertTrue(AppointmentRevision::factory()->for($booking)->create()->appointment->is($booking));
        $link = AppointmentFollowUp::factory()->create();
        $this->assertSame('attended', $link->previousAppointment->status);
        $this->assertTrue($link->appointment->followUpLink->is($link));
        $this->assertDatabaseCount('permissions', 34);
        $this->assertDatabaseCount('role_permissions', 21);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }
}
