<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentFollowUp;
use App\Models\AppointmentRevision;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 11)->setTime(8, 0));
        $this->seed(DatabaseSeeder::class);
        $this->registrar = $this->createStaffWithRole('frontdesk');
        $this->doctor = $this->createStaffWithRole('doctor');
        $this->grantRolePermissions('frontdesk', ['appointments.view', 'appointments.create', 'appointments.update', 'patients.view']);
        $this->actingAs($this->registrar);
    }

    public function test_booking_before_registration_saves_utc_and_private_history_with_safe_audit(): void
    {
        $data = $this->data();
        $data['phone'] = '234 801-234-5678';
        $response = $this->postCommand('/api/v1/appointments', $data)->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $id = $response->json('data.id');
        $this->getJson('/api/v1/appointments/'.$id)->assertOk()->assertJsonPath('data.patient_id', null)
            ->assertJsonPath('data.first_name', 'Private-Caller')->assertJsonPath('data.phone', '+2348012345678')
            ->assertJsonPath('data.starts_at', '2026-11-20T08:00:00.000000Z')->assertJsonPath('data.ends_at', '2026-11-20T08:30:00.000000Z')
            ->assertJsonPath('data.booked_by', $this->registrar->id)->assertJsonPath('data.version', 1)
            ->assertJsonPath('meta.timezone', 'Africa/Lagos')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/appointments/'.$id.'/history')->assertOk()->assertJsonPath('data.0.operation', 'created')
            ->assertJsonPath('data.0.snapshot.phone', '+2348012345678')->assertJsonPath('data.0.actor_id', $this->registrar->id);
        $booking = Appointment::findOrFail($id);
        $this->assertTrue($booking->revisions->sole()->actor->is($this->registrar));
        $this->assertTrue($booking->revisions->sole()->appointment->is($booking));
        $this->assertAuditEventRecorded('appointment.created', $this->registrar->id, 'appointment', $id,
            summary: ['version' => 1, 'record_id' => $booking->revisions->sole()->id]);
        $this->assertAuditExcludes(['Private-Caller', '+2348012345678', 'Snapshot-Doctor', 'private.specialist@example.test', '2026-11-20T09:00:00+01:00']);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseHas('idempotency_requests', ['resource_type' => 'appointment', 'resource_id' => $id, 'resource_version' => 1]);
    }

    public function test_registration_snapshot_is_independent_and_edits_reschedules_and_confirmation_keep_original_history(): void
    {
        $patient = Patient::factory()->create();
        $id = $this->postCommand('/api/v1/appointments', $this->data(['patient_id' => $patient->id]))->assertCreated()->json('data.id');
        $patient->update(['first_name' => 'Changed Patient', 'phone' => '+2348099999999']);
        $this->patchCommand('/api/v1/appointments/'.$id, ['version' => 1, 'reason' => 'Corrected caller contact', 'phone' => '+2348077777777'])
            ->assertOk()->assertJsonPath('data.first_name', 'Private-Caller')->assertJsonPath('data.version', 2);
        $this->patchCommand('/api/v1/appointments/'.$id, ['version' => 2, 'reason' => 'Caller requested another day',
            'starts_at' => '2026-11-21T09:00:00+01:00', 'ends_at' => '2026-11-21T10:00:00+01:00'])->assertOk()->assertJsonPath('data.version', 3);
        $this->postCommand('/api/v1/appointments/'.$id.'/confirm', ['version' => 3, 'reason' => 'Caller confirmed attendance'])
            ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.version', 4);
        $this->getJson('/api/v1/appointments/'.$id.'/history')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.snapshot.phone', '+2348012345678')->assertJsonPath('data.1.snapshot.phone', '+2348077777777')
            ->assertJsonPath('data.2.operation', 'rescheduled')->assertJsonPath('data.2.snapshot.starts_at', '2026-11-21T08:00:00.000000Z')
            ->assertJsonPath('data.3.operation', 'confirmed');
        $this->assertAuditExcludes(['Corrected caller contact', 'Caller requested another day', '+2348077777777']);
    }

    public function test_create_and_update_confirm_once_after_lost_responses_and_reject_changed_or_outdated_replays(): void
    {
        $headers = ['Idempotency-Key' => 'booking-create-key-0001'];
        $id = $this->postCommand('/api/v1/appointments', $this->data(), $headers)->assertCreated()->json('data.id');
        $this->postCommand('/api/v1/appointments', $this->data(), $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->assertApiFailure($this->postCommand('/api/v1/appointments', $this->data(['phone' => '+2348000000000']), $headers), 409, 'idempotency_conflict');
        $edit = ['version' => 1, 'reason' => 'Caller changed contact', 'first_name' => 'Updated Caller'];
        $editKey = ['Idempotency-Key' => 'booking-update-key-0001'];
        $this->patchCommand('/api/v1/appointments/'.$id, $edit, $editKey)->assertOk();
        $this->patchCommand('/api/v1/appointments/'.$id, $edit, $editKey)->assertOk()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.version', 2);
        $this->assertApiFailure($this->postCommand('/api/v1/appointments', $this->data(), $headers), 409, 'idempotency_outdated');
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_revisions', 2);
        $this->assertDatabaseCount('idempotency_requests', 2);
    }

    public function test_conflicts_cover_hidden_and_unregistered_bookings_without_disclosing_them_and_allow_back_to_back(): void
    {
        $patient = Patient::factory()->create(['access_status' => 'restricted']);
        Appointment::factory()->create(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-20 08:00:00', 'ends_at' => '2026-11-20 08:30:00']);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/appointments', $this->data()));
        $this->assertApiFailure($response, 409, 'appointment_conflict');
        $response->assertJsonMissingPath('data')->assertJsonMissingPath('conflicting_appointment_id');
        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(0, 'data');
        $this->postCommand('/api/v1/appointments', $this->data(['starts_at' => '2026-11-20T09:30:00+01:00', 'ends_at' => '2026-11-20T10:00:00+01:00']))->assertCreated();
        $other = $this->createStaffWithRole('doctor');
        $this->postCommand('/api/v1/appointments', $this->data(['doctor_id' => $other->id]))->assertCreated();
        $this->postCommand('/api/v1/appointments', $this->data(['doctor_id' => null]))->assertCreated();
        $this->postCommand('/api/v1/appointments', $this->data(['doctor_id' => null]))->assertCreated();
    }

    #[DataProvider('overlappingIntervals')]
    public function test_all_forms_of_interval_overlap_are_rejected(string $start, string $end): void
    {
        $this->postCommand('/api/v1/appointments', $this->data())->assertCreated();
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/appointments', $this->data(['starts_at' => $start, 'ends_at' => $end])));
        $this->assertApiFailure($response, 409, 'appointment_conflict');
    }

    public static function overlappingIntervals(): array
    {
        return [
            'same' => ['2026-11-20T09:00:00+01:00', '2026-11-20T09:30:00+01:00'],
            'inside' => ['2026-11-20T09:10:00+01:00', '2026-11-20T09:20:00+01:00'],
            'surrounds' => ['2026-11-20T08:50:00+01:00', '2026-11-20T09:40:00+01:00'],
            'left' => ['2026-11-20T08:50:00+01:00', '2026-11-20T09:10:00+01:00'],
            'right' => ['2026-11-20T09:20:00+01:00', '2026-11-20T09:40:00+01:00'],
        ];
    }

    public function test_cancellation_releases_slot_and_terminal_bookings_cannot_be_edited_or_reopened(): void
    {
        $id = $this->postCommand('/api/v1/appointments', $this->data())->assertCreated()->json('data.id');
        $key = ['Idempotency-Key' => 'booking-cancel-key-0001'];
        $command = ['version' => 1, 'reason' => 'Caller cancelled their appointment'];
        $this->postCommand('/api/v1/appointments/'.$id.'/cancel', $command, $key)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postCommand('/api/v1/appointments/'.$id.'/cancel', $command, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->postCommand('/api/v1/appointments', $this->data())->assertCreated();
        $this->assertApiFailure($this->patchCommand('/api/v1/appointments/'.$id, ['version' => 2, 'reason' => 'Attempt to reopen booking', 'first_name' => 'Changed']), 409, 'appointment_terminal');
        $this->assertApiFailure($this->postCommand('/api/v1/appointments/'.$id.'/confirm', ['version' => 2, 'reason' => 'Attempt to confirm again']), 409, 'appointment_terminal');
    }

    public function test_no_show_waits_until_interval_end_and_stale_edits_never_overwrite(): void
    {
        $id = $this->postCommand('/api/v1/appointments', $this->data())->assertCreated()->json('data.id');
        $this->assertApiFailure($this->postCommand('/api/v1/appointments/'.$id.'/no-show', ['version' => 1, 'reason' => 'Patient has not arrived']), 409, 'appointment_not_due');
        $this->patchCommand('/api/v1/appointments/'.$id, ['version' => 1, 'reason' => 'Corrected caller details', 'first_name' => 'First saved name'])->assertOk();
        $this->assertApiFailure($this->patchCommand('/api/v1/appointments/'.$id, ['version' => 1, 'reason' => 'Stale caller details', 'first_name' => 'Stale overwrite']), 409, 'version_conflict');
        $this->travelTo(now('UTC')->setDate(2026, 11, 20)->setTime(8, 30));
        $this->postCommand('/api/v1/appointments/'.$id.'/no-show', ['version' => 2, 'reason' => 'No arrival after scheduled interval'])
            ->assertOk()->assertJsonPath('data.status', 'no_show')->assertJsonPath('data.first_name', 'First saved name');
    }

    public function test_follow_up_links_attended_booking_for_same_patient_and_preserves_ownership(): void
    {
        $patient = Patient::factory()->create();
        $previous = Appointment::factory()->registered($patient)->create(['starts_at' => '2026-11-10 08:00:00', 'ends_at' => '2026-11-10 08:30:00']);
        $previous->forceFill(['status' => 'attended', 'version' => 2])->save();
        $id = $this->postCommand('/api/v1/appointments', $this->data(['patient_id' => $patient->id, 'follow_up_of_id' => $previous->id]))
            ->assertCreated()->assertJsonPath('data.follow_up_of_id', $previous->id)->json('data.id');
        $link = AppointmentFollowUp::findOrFail($id);
        $this->assertTrue($link->appointment->is(Appointment::findOrFail($id)));
        $this->assertTrue($link->previousAppointment->is($previous));
        $this->assertTrue($link->createdBy->is($this->registrar));
        $this->assertTrue($previous->followUpLinks->sole()->is($link));
        $this->getJson('/api/v1/appointments/'.$id.'/history')->assertOk()->assertJsonPath('data.0.snapshot.follow_up_of_id', $previous->id);
        foreach (['update', 'delete'] as $operation) {
            try {
                $this->assertTablesUnchanged($this->tables(), function () use ($id, $previous, $operation): void {
                    $query = DB::table('appointment_follow_ups')->where('appointment_id', $id);
                    if ($operation === 'update') {
                        $query->update(['previous_appointment_id' => $previous->id]);
                    } else {
                        $query->delete();
                    }
                });
                $this->fail('Follow-up ownership must be retained.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertApiFailure($this->postCommand('/api/v1/appointments', $this->data(['patient_id' => Patient::factory()->create()->id,
            'follow_up_of_id' => $previous->id, 'doctor_id' => null])), 422, 'validation_failed', ['follow_up_of_id']);
        $this->patchCommand('/api/v1/appointments/'.$id, ['version' => 1, 'reason' => 'Attempt to move ownership', 'patient_id' => $patient->id])->assertUnprocessable();
        $this->grantRolePermissions('frontdesk', ['patients.merges.request', 'patients.merges.approve']);
        $target = Patient::factory()->create();
        $proposal = $this->postCommand('/api/v1/patients/'.$patient->id.'/merge-requests', ['version' => 1,
            'target_patient_id' => $target->id, 'target_version' => 1, 'reason' => 'Synthetic duplicate identity review',
            'current_password' => self::STAFF_TEST_PASSWORD])->assertCreated()->json('data.record_id');
        $lateId = $this->postCommand('/api/v1/appointments', $this->data(['patient_id' => $patient->id, 'doctor_id' => null]))
            ->assertCreated()->json('data.id');
        $reviewer = $this->createStaffWithRole('frontdesk');
        $this->flushSession();
        $this->actingAs($reviewer);
        $approval = ['version' => 2, 'request_version' => 1, 'reason' => 'Independent synthetic identity review',
            'current_password' => self::STAFF_TEST_PASSWORD];
        $approvalKey = ['Idempotency-Key' => 'booking-merge-approval-0001'];
        $path = '/api/v1/patients/'.$patient->id.'/merge-requests/'.$proposal.'/approve';
        $response = $this->assertTablesUnchanged([...$this->tables(), 'patients', 'patient_merge_requests', 'patient_merges'],
            fn () => $this->postCommand($path, $approval, $approvalKey));
        $this->assertApiFailure($response, 409, 'merge_appointments_pending');
        $this->postCommand('/api/v1/appointments/'.$id.'/cancel', ['version' => 1, 'reason' => 'Resolve original follow-up before merge'])->assertOk();
        $this->assertApiFailure($this->postCommand($path, $approval, $approvalKey), 409, 'merge_appointments_pending');
        $this->postCommand('/api/v1/appointments/'.$lateId.'/cancel', ['version' => 1, 'reason' => 'Resolve later booking before merge'])->assertOk();
        $this->postCommand($path, $approval, $approvalKey)->assertOk();
        $this->assertSame('merged', $patient->fresh()->access_status);
        $this->assertDatabaseHas('appointments', ['id' => $id, 'patient_id' => $patient->id, 'status' => 'cancelled']);
        $this->getJson('/api/v1/appointments/'.$id)->assertNotFound();
    }

    #[DataProvider('hiddenStates')]
    public function test_hidden_patient_bookings_cannot_be_listed_read_changed_replayed_or_used_for_follow_up(string $status): void
    {
        $patient = Patient::factory()->create();
        $key = ['Idempotency-Key' => 'booking-private-key-0001'];
        $data = $this->data(['patient_id' => $patient->id]);
        $id = $this->postCommand('/api/v1/appointments', $data, $key)->assertCreated()->json('data.id');
        $patient->forceFill(['access_status' => $status])->save();
        $this->getJson('/api/v1/appointments?q=Private-Caller')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/appointments/'.$id)->assertNotFound();
        $this->getJson('/api/v1/appointments/'.$id.'/history')->assertNotFound();
        $this->postCommand('/api/v1/appointments', $data, $key)->assertNotFound();
        $this->postCommand('/api/v1/appointments', $data)->assertNotFound();
        $this->patchCommand('/api/v1/appointments/'.$id, ['version' => 1, 'reason' => 'Change hidden booking', 'first_name' => 'Hidden'])->assertNotFound();
        $this->postCommand('/api/v1/appointments/'.$id.'/cancel', ['version' => 1, 'reason' => 'Cancel hidden booking'])->assertNotFound();
        $this->postCommand('/api/v1/appointments', $this->data(['doctor_id' => null, 'follow_up_of_id' => $id]))->assertNotFound();
    }

    public static function hiddenStates(): array
    {
        return [['restricted'], ['merged']];
    }

    public function test_explicit_permissions_are_required_and_patient_permission_only_controls_linked_bookings(): void
    {
        $patient = Patient::factory()->create();
        $linked = $this->postCommand('/api/v1/appointments', $this->data(['patient_id' => $patient->id]))->assertCreated()->json('data.id');
        $unregistered = $this->postCommand('/api/v1/appointments', $this->data(['doctor_id' => null]))->assertCreated()->json('data.id');
        $reader = $this->createStaffWithRole('hmo');
        $this->flushSession();
        $this->actingAs($reader)->getJson('/api/v1/appointments')->assertForbidden();
        $this->grantRolePermissions('hmo', ['appointments.view']);
        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $unregistered);
        $this->getJson('/api/v1/appointments/'.$linked)->assertNotFound();
        $this->postCommand('/api/v1/appointments', $this->data())->assertForbidden();
        $this->grantRolePermissions('hmo', ['patients.view']);
        $this->getJson('/api/v1/appointments/'.$linked)->assertOk();
        $this->patchCommand('/api/v1/appointments/'.$linked, ['version' => 1, 'reason' => 'Reader cannot edit', 'first_name' => 'No'])->assertForbidden();
        $this->grantRolePermissions('hmo', ['appointments.create']);
        $this->postCommand('/api/v1/appointments', $this->data(['doctor_id' => null]))->assertCreated();
    }

    public function test_guest_inactive_and_revoked_permissions_are_checked_for_reads_and_replays(): void
    {
        $key = ['Idempotency-Key' => 'booking-revoked-key-0001'];
        $this->postCommand('/api/v1/appointments', $this->data(), $key)->assertCreated();
        Role::where('code', 'frontdesk')->sole()->permissions()->detach(Permission::where('code', 'appointments.create')->sole());
        $this->postCommand('/api/v1/appointments', $this->data(), $key)->assertForbidden();
        Role::where('code', 'frontdesk')->sole()->permissions()->detach(Permission::where('code', 'appointments.view')->sole());
        $this->getJson('/api/v1/appointments')->assertForbidden();
        $this->registrar->forceFill(['is_active' => false])->save();
        $this->getJson('/api/v1/appointments')->assertUnauthorized();
        $this->flushSession();
        $this->getJson('/api/v1/appointments')->assertUnauthorized();
    }

    public function test_search_pagination_literal_wildcards_and_hospital_local_date_boundaries(): void
    {
        $this->postCommand('/api/v1/appointments', $this->data(['first_name' => 'Literal% Caller', 'doctor_id' => null,
            'starts_at' => '2026-11-20T00:15:00+01:00', 'ends_at' => '2026-11-20T00:30:00+01:00']))->assertCreated();
        $this->postCommand('/api/v1/appointments', $this->data(['first_name' => 'LiteralX Caller', 'doctor_id' => null,
            'starts_at' => '2026-11-19T23:59:00+01:00', 'ends_at' => '2026-11-20T00:01:00+01:00']))->assertCreated();
        $this->postCommand('/api/v1/appointments', $this->data(['doctor_id' => null, 'starts_at' => '2026-11-20T23:59:00+01:00', 'ends_at' => '2026-11-21T00:15:00+01:00']))->assertCreated();
        $this->getJson('/api/v1/appointments?q=Literal%25')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Literal% Caller');
        $this->getJson('/api/v1/appointments?from_date=2026-11-20&to_date=2026-11-20&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('timezone', 'Africa/Lagos')
            ->assertJsonPath('data.0.starts_at', '2026-11-19T23:15:00.000000Z');
        $this->getJson('/api/v1/appointments?from_date=2026-11-20&to_date=2026-11-20&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.0.starts_at', '2026-11-20T22:59:00.000000Z');
    }

    public function test_booking_options_and_roster_only_expose_active_clinician_ids_and_names(): void
    {
        $disabled = $this->createStaffWithRole('doctor', ['is_active' => false]);
        $this->getJson('/api/v1/appointments/options')->assertOk()->assertJsonPath('data.timezone', 'Africa/Lagos')
            ->assertJsonPath('data.requires_end_time', true)->assertJsonPath('data.clinician_overlap_allowed', false);
        $this->getJson('/api/v1/appointments/clinicians')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->doctor->id)
            ->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.username')->assertJsonMissingPath('data.0.password');
        foreach ([$disabled->id, $this->registrar->id, 99999999] as $id) {
            $this->postCommand('/api/v1/appointments', $this->data(['doctor_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('doctor_id');
        }
    }

    public function test_numeric_ids_and_precise_instants_keep_their_types_and_microseconds(): void
    {
        $patient = Patient::factory()->create();
        $this->postCommand('/api/v1/appointments', $this->data(['patient_id' => (string) $patient->id,
            'doctor_id' => (string) $this->doctor->id, 'first_name' => 'Zoë',
            'starts_at' => '2026-11-20T08:00:00.123456Z', 'ends_at' => '2026-11-20T09:00:00.123457+01:00']))
            ->assertCreated()->assertJsonPath('data.patient_id', $patient->id)->assertJsonPath('data.doctor_id', $this->doctor->id)
            ->assertJsonPath('data.first_name', 'Zoë')->assertJsonPath('data.starts_at', '2026-11-20T08:00:00.123456Z')
            ->assertJsonPath('data.ends_at', '2026-11-20T08:00:00.123457Z');
    }

    public function test_unicode_name_and_reason_limits_count_characters_on_both_databases(): void
    {
        $name = str_repeat('é', 64);
        $id = $this->postCommand('/api/v1/appointments', $this->data(['first_name' => $name]))->assertCreated()
            ->assertJsonPath('data.first_name', $name)->json('data.id');
        $reason = str_repeat('é', 2000);
        $this->patchCommand('/api/v1/appointments/'.$id, ['version' => 1, 'reason' => $reason, 'last_name' => 'Unicode update'])->assertOk();
        $this->getJson('/api/v1/appointments/'.$id.'/history')->assertOk()->assertJsonPath('data.1.reason', $reason);
        $this->assertAuditExcludes([$name, $reason]);
    }

    public function test_legacy_unknown_duration_reserves_the_clinician_until_corrected_or_cancelled(): void
    {
        $legacy = Appointment::factory()->create(['doctor_id' => $this->doctor->id,
            'starts_at' => '2026-11-20 07:00:00', 'ends_at' => null]);
        $this->assertApiFailure($this->postCommand('/api/v1/appointments', $this->data()), 409, 'appointment_conflict');
        $this->postCommand('/api/v1/appointments/'.$legacy->id.'/no-show', ['version' => 1, 'reason' => 'Unknown duration cannot establish no-show'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->postCommand('/api/v1/appointments/'.$legacy->id.'/cancel', ['version' => 1, 'reason' => 'Legacy interval cannot be confirmed'])->assertOk();
        $this->postCommand('/api/v1/appointments', $this->data())->assertCreated();
    }

    public function test_expired_key_never_creates_a_second_booking_and_keys_cannot_move_between_edit_targets(): void
    {
        $key = ['Idempotency-Key' => 'booking-expired-key-0001'];
        $first = $this->postCommand('/api/v1/appointments', $this->data(), $key)->assertCreated()->json('data.id');
        DB::table('idempotency_requests')->update(['expires_at' => now('UTC')->subSecond()]);
        $this->assertApiFailure($this->postCommand('/api/v1/appointments', $this->data(), $key), 409, 'idempotency_expired');
        $second = $this->postCommand('/api/v1/appointments', $this->data(['doctor_id' => null]))->assertCreated()->json('data.id');
        $change = ['version' => 1, 'reason' => 'Caller changed contact', 'phone' => '+2348099999999'];
        $editKey = ['Idempotency-Key' => 'booking-bound-target-0001'];
        $this->patchCommand('/api/v1/appointments/'.$first, $change, $editKey)->assertOk();
        $this->assertApiFailure($this->patchCommand('/api/v1/appointments/'.$second, $change, $editKey), 409, 'idempotency_conflict');
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_failed_follow_up_link_rolls_back_the_booking_and_confirmation(): void
    {
        $previous = Appointment::factory()->registered(Patient::factory()->create())
            ->create(['starts_at' => '2026-11-10 08:00:00']);
        $previous->forceFill(['status' => 'attended', 'version' => 2])->save();
        $hit = $this->failNextWriteTo('appointment_follow_ups', 'insert');
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/appointments',
            $this->data(['patient_id' => $previous->patient_id, 'follow_up_of_id' => $previous->id])));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertNoSuccessfulAudit('appointment.created');
    }

    #[DataProvider('invalidBookings')]
    public function test_invalid_booking_fields_fail_without_partial_records(array $changes, string $field): void
    {
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/appointments', $this->data($changes)));
        $this->assertApiFailure($response, 422, 'validation_failed', [$field]);
    }

    public static function invalidBookings(): array
    {
        return [
            'blank name' => [['first_name' => ' '], 'first_name'], 'long name' => [['first_name' => str_repeat('x', 65)], 'first_name'],
            'bad phone' => [['phone' => 'not a phone'], 'phone'], 'local prefix not guessed' => [['phone' => '08012345678'], 'phone'],
            'missing end' => [['ends_at' => null], 'ends_at'], 'end before start' => [['ends_at' => '2026-11-20T08:59:59+01:00'], 'ends_at'],
            'end equals start' => [['ends_at' => '2026-11-20T09:00:00+01:00'], 'ends_at'],
            'naive time' => [['starts_at' => '2026-11-20 09:00:00'], 'starts_at'],
            'impossible date' => [['starts_at' => '2026-02-30T09:00:00Z'], 'starts_at'],
            'bad hour' => [['starts_at' => '2026-11-20T25:00:00Z'], 'starts_at'],
            'bad offset' => [['starts_at' => '2026-11-20T09:00:00+15:00'], 'starts_at'],
            'past' => [['starts_at' => '2026-11-10T09:00:00Z'], 'starts_at'],
            'bad channel' => [['booking_channel' => 'Fake'], 'booking_channel'], 'bad visit' => [['visit_type' => 'Fake'], 'visit_type'],
            'bad specialist email' => [['specialist_email' => 'invalid'], 'specialist_email'],
            'actor injection' => [['booked_by' => 99999999], 'booked_by'], 'status injection' => [['status' => 'attended'], 'status'],
            'version injection' => [['version' => 5], 'version'], 'unknown field' => [['patient_snapshot' => ['name' => 'Wrong']], 'patient_snapshot'],
            'invalid patient id' => [['patient_id' => -1], 'patient_id'], 'invalid doctor id' => [['doctor_id' => 'abc'], 'doctor_id'],
        ];
    }

    #[DataProvider('failureBoundaries')]
    public function test_booking_history_audit_and_command_completion_roll_back_together(string $table, string $verb): void
    {
        $hit = $this->failNextWriteTo($table, $verb);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/appointments', $this->data()));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertNoSuccessfulAudit('appointment.created');
        $this->assertAuditEventRecorded('appointment.created', $this->registrar->id, 'appointment', null, 'failed');
    }

    public static function failureBoundaries(): array
    {
        return [['appointments', 'insert'], ['appointment_revisions', 'insert'], ['audit_events', 'insert'], ['idempotency_requests', 'update']];
    }

    public function test_stale_rescheduling_conflict_and_injected_edit_failure_leave_original_slot_and_history_intact(): void
    {
        $first = $this->postCommand('/api/v1/appointments', $this->data())->assertCreated()->json('data.id');
        $second = $this->postCommand('/api/v1/appointments', $this->data(['starts_at' => '2026-11-20T10:00:00+01:00', 'ends_at' => '2026-11-20T10:30:00+01:00']))->assertCreated()->json('data.id');
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->patchCommand('/api/v1/appointments/'.$second,
            ['version' => 1, 'reason' => 'Requested occupied interval', 'starts_at' => '2026-11-20T09:00:00+01:00', 'ends_at' => '2026-11-20T09:30:00+01:00']));
        $this->assertApiFailure($response, 409, 'appointment_conflict');
        $hit = $this->failNextWriteTo('appointment_revisions', 'insert');
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->patchCommand('/api/v1/appointments/'.$first,
            ['version' => 1, 'reason' => 'Change that must roll back', 'first_name' => 'Rolled-back name']));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
    }

    public function test_booking_database_errors_do_not_log_private_sql_or_bindings(): void
    {
        Log::spy();
        $hit = false;
        DB::listen(function (QueryExecuted $event) use (&$hit): void {
            if (! $hit && preg_match('/^insert into [`"]appointments[`"]/i', $event->sql) === 1) {
                $hit = true;
                throw new QueryException('testing', 'Private SQL', ['Private-Caller'], new PDOException('Private failure'));
            }
        });
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/appointments', $this->data()));
        $this->assertTrue($hit);
        $this->assertApiFailure($response, 500, 'server_error');
        Log::shouldHaveReceived('error')->once()->with('An appointment database operation failed.');
    }

    public function test_audit_review_can_filter_booking_events_without_access_to_private_booking_details(): void
    {
        $id = $this->postCommand('/api/v1/appointments', $this->data())->assertCreated()->json('data.id');
        $reviewer = $this->createStaffWithRole('admin');
        $this->flushSession();
        $this->actingAs($reviewer)->getJson('/api/v1/audit-events?subject_type=appointment&subject_id='.$id.'&action=appointment.created')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject_type', 'appointment')
            ->assertJsonPath('data.0.safe_summary.version', 1)->assertJsonMissingPath('data.0.safe_summary.snapshot');
        $this->getJson('/api/v1/appointments/'.$id)->assertForbidden();
        $this->assertAuditExcludes(['Private-Caller', 'private.specialist@example.test', '+2348012345678']);
    }

    public function test_private_history_is_immutable_and_cannot_be_reassigned_or_forged_for_another_booking(): void
    {
        $id = $this->postCommand('/api/v1/appointments', $this->data())->assertCreated()->json('data.id');
        $revision = AppointmentRevision::where('appointment_id', $id)->sole();
        foreach (['update', 'delete'] as $operation) {
            try {
                $this->assertTablesUnchanged($this->tables(), function () use ($revision, $operation): void {
                    $query = DB::table('appointment_revisions')->where('id', $revision->id);
                    if ($operation === 'update') {
                        $query->update(['reason' => 'History rewrite']);
                    } else {
                        $query->delete();
                    }
                });
                $this->fail('History changes must be rejected.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $other = Appointment::factory()->create();
        $forged = $revision->getAttributes();
        unset($forged['id']);
        $forged['appointment_id'] = $other->id;
        try {
            $this->assertTablesUnchanged($this->tables(), fn () => DB::table('appointment_revisions')->insert($forged));
            $this->fail('A snapshot cannot be moved to another booking.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function tables(): array
    {
        return ['appointments', 'appointment_revisions', 'appointment_follow_ups', 'idempotency_requests'];
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private function data(array $changes = []): array
    {
        return array_replace(['first_name' => 'Private-Caller', 'last_name' => 'Booking-Sentinel', 'phone' => '+2348012345678',
            'starts_at' => '2026-11-20T09:00:00+01:00', 'ends_at' => '2026-11-20T09:30:00+01:00',
            'booking_channel' => 'Voice Call', 'visit_type' => 'General Consultation', 'doctor_id' => $this->doctor->id,
            'specialist_name' => 'Snapshot-Doctor', 'specialist_email' => 'private.specialist@example.test'], $changes);
    }
}
