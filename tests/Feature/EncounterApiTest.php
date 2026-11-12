<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\EncounterRevision;
use App\Models\Patient;
use App\Models\PatientMergeRequest;
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

class EncounterApiTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;
    private User $doctor;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 11)->setTime(8, 0));
        $this->seed(DatabaseSeeder::class);
        $this->registrar = $this->createStaffWithRole('frontdesk');
        $this->doctor = $this->createStaffWithRole('doctor');
        $this->patient = Patient::factory()->create(['first_name' => 'Private-Patient']);
        $this->grantRolePermissions('frontdesk', ['patients.view', 'appointments.view', 'encounters.view', 'encounters.view_any',
            'encounters.arrive', 'encounters.assign', 'encounters.cancel']);
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'encounters.progress']);
        $this->actingAs($this->registrar);
    }

    public function test_arrival_links_verified_identity_preserves_booking_snapshot_and_opens_one_unassigned_visit(): void
    {
        $booking = Appointment::factory()->create(['first_name' => 'Original-Caller', 'doctor_id' => $this->doctor->id]);
        $data = $this->opening() + ['version' => 1, 'reason' => 'Private identity verification'];
        unset($data['visit_type']);
        $headers = ['Idempotency-Key' => 'arrival-confirmation-0001'];
        $id = $this->postCommand('/api/v1/appointments/'.$booking->id.'/arrive', $data, $headers)->assertCreated()
            ->assertJsonPath('data.patient_id', $this->patient->id)->assertJsonPath('data.appointment_id', $booking->id)
            ->assertJsonPath('data.category_id', $this->patient->category_id)->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.queue', 'unassigned')->assertJsonPath('data.current_assignment', null)
            ->assertJsonPath('data.opened_at', '2026-11-11T08:00:00.000000Z')->json('data.id');
        $this->postCommand('/api/v1/appointments/'.$booking->id.'/arrive', $data, $headers)->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
        $this->assertSame('attended', $booking->fresh()->status);
        $this->assertSame('Original-Caller', $booking->fresh()->first_name);
        $this->assertSame($this->patient->id, $booking->fresh()->patient_id);
        $this->getJson('/api/v1/appointments/'.$booking->id.'/history')->assertOk()->assertJsonPath('data.0.operation', 'attended')
            ->assertJsonPath('data.0.snapshot.version', 2);
        $this->assertDatabaseCount('encounters', 1);
        $this->assertDatabaseCount('doctor_assignments', 0);
        $this->assertDatabaseCount('encounter_revisions', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertDatabaseHas('idempotency_requests', ['resource_type' => 'encounter', 'resource_id' => $id, 'resource_version' => 1]);
        $this->assertAuditExcludes(['Original-Caller', 'Private-Patient', 'Private identity verification']);
        $this->assertAuditEventRecorded('appointment.attended', $this->registrar->id, 'appointment', $booking->id);
    }

    public function test_walk_in_assign_accept_complete_and_retry_preserve_versions_history_and_ownership(): void
    {
        $id = $this->openVisit();
        $assigned = $this->assign($id)->assertOk()->assertJsonPath('data.queue', 'waiting')->assertJsonPath('data.version', 2);
        $assignment = $assigned->json('data.current_assignment');
        $this->signIn($this->doctor);
        $accept = $this->transition(2, $assignment);
        $acceptKey = ['Idempotency-Key' => 'accept-confirmation-0001'];
        $this->postCommand('/api/v1/encounters/'.$id.'/accept', $accept, $acceptKey)->assertOk()
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.queue', 'in_progress')->assertJsonPath('data.version', 3);
        $this->postCommand('/api/v1/encounters/'.$id.'/accept', $accept, $acceptKey)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $assignment['version'] = 2;
        $complete = $this->transition(3, $assignment);
        $completeKey = ['Idempotency-Key' => 'complete-confirmation-0001'];
        $this->postCommand('/api/v1/encounters/'.$id.'/complete', $complete, $completeKey)->assertOk()
            ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.queue', null)->assertJsonPath('data.current_assignment', null)->assertJsonPath('data.version', 4);
        $this->postCommand('/api/v1/encounters/'.$id.'/complete', $complete, $completeKey)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->getJson('/api/v1/queues')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/encounters/'.$id)->assertOk();
        $this->getJson('/api/v1/encounters/'.$id.'/history')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.operation', 'opened')->assertJsonPath('data.1.operation', 'assigned')
            ->assertJsonPath('data.2.operation', 'accepted')->assertJsonPath('data.3.operation', 'completed');
        $row = DoctorAssignment::findOrFail($assignment['id']);
        $this->assertSame('completed', $row->status);
        $this->assertSame($this->doctor->id, $row->ended_by);
        $this->assertSame($this->registrar->id, $row->assigned_by);
        $this->assertSame(3, $row->version);
        $this->assertDatabaseCount('doctor_assignments', 1);
        $this->assertAuditExcludes(['Private-Patient', 'Private visit reason']);
    }

    public function test_reassignment_ends_previous_clinician_access_retains_history_and_cannot_cancel_started_care(): void
    {
        $id = $this->openVisit();
        $assignment = $this->assign($id)->assertOk()->json('data.current_assignment');
        $this->signIn($this->doctor);
        $this->postCommand('/api/v1/encounters/'.$id.'/accept', $this->transition(2, $assignment))->assertOk();
        $this->signIn($this->registrar);
        $other = $this->createStaffWithRole('doctor');
        $assignment['version'] = 2;
        $data = $this->transition(3, $assignment) + ['doctor_id' => $other->id, 'consultation_type' => 'General Consultation'];
        $this->postCommand('/api/v1/encounters/'.$id.'/reassign', $data)->assertOk()
            ->assertJsonPath('data.version', 4)->assertJsonPath('data.queue', 'waiting')->assertJsonPath('data.status', 'in_progress');
        $this->assertApiFailure($this->postCommand('/api/v1/encounters/'.$id.'/cancel', ['version' => 4, 'reason' => 'Private visit reason']), 409, 'encounter_care_started');
        $this->assertSame('reassigned', DoctorAssignment::findOrFail($assignment['id'])->status);
        $this->assertNotNull(DoctorAssignment::findOrFail($assignment['id'])->accepted_at);
        $this->assertDatabaseCount('doctor_assignments', 2);
        $this->getJson('/api/v1/encounters/'.$id.'/assignments')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'reassigned')->assertJsonPath('data.0.end_reason', 'Private visit reason');
        $this->getJson('/api/v1/encounters/'.$id.'/history')->assertOk()->assertJsonPath('data.3.snapshot.assignments.0.status', 'reassigned');
        $this->signIn($this->doctor);
        $this->getJson('/api/v1/queues')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/encounters/'.$id)->assertNotFound();
        $this->getJson('/api/v1/encounters/'.$id.'/history')->assertNotFound();
        $this->getJson('/api/v1/encounters/'.$id.'/assignments')->assertNotFound();
        $this->postCommand('/api/v1/encounters/'.$id.'/complete', $this->transition(4, $assignment))->assertNotFound();
        $this->signIn($other);
        $this->getJson('/api/v1/queues')->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_cancel_before_acceptance_ends_assignment_and_is_replayable_but_terminal_visits_do_not_reopen(): void
    {
        $id = $this->openVisit();
        $this->assign($id)->assertOk();
        $data = ['version' => 2, 'reason' => 'Private cancellation reason'];
        $key = ['Idempotency-Key' => 'cancel-visit-confirmation-0001'];
        $this->postCommand('/api/v1/encounters/'.$id.'/cancel', $data, $key)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postCommand('/api/v1/encounters/'.$id.'/cancel', $data, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame('cancelled', Encounter::findOrFail($id)->assignments->sole()->status);
        $this->assertApiFailure($this->assign($id, 3), 409, 'encounter_terminal');
        $unassigned = $this->openVisit();
        $this->postCommand('/api/v1/encounters/'.$unassigned.'/cancel', ['version' => 1, 'reason' => 'Private cancellation reason'])->assertOk();
    }

    public function test_queues_are_fifo_paginated_filtered_on_lagos_days_and_hide_other_clinicians(): void
    {
        $this->travelTo(now('UTC')->setTime(22, 59));
        $yesterday = $this->openVisit();
        $this->travelTo(now('UTC')->setTime(23, 0));
        $first = $this->openVisit();
        $second = $this->openVisit();
        $third = $this->openVisit();
        $this->assign($second)->assertOk();
        $other = $this->createStaffWithRole('doctor');
        $this->assign($third, doctor: $other)->assertOk();
        $this->getJson('/api/v1/queues?from_date=2026-11-12&to_date=2026-11-12&per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $first)->assertJsonPath('data.1.id', $second)->assertJsonPath('meta.total', 3)
            ->assertJsonPath('timezone', 'Africa/Lagos');
        $this->getJson('/api/v1/queues?queue=unassigned')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $yesterday);
        $this->getJson('/api/v1/queues?doctor_id='.$other->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $third);
        $this->signIn($this->doctor);
        $this->getJson('/api/v1/queues')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $second);
        $this->getJson('/api/v1/queues?doctor_id='.$other->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/encounters/'.$first)->assertNotFound();
        $this->getJson('/api/v1/encounters/'.$third.'/history')->assertNotFound();
    }

    #[DataProvider('invalidOpenings')]
    public function test_invalid_walk_in_input_never_creates_care_or_commands(array $changes, string $field): void
    {
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/encounters', array_replace($this->opening(), $changes)));
        $this->assertApiFailure($response, 422, 'validation_failed', [$field]);
    }

    public static function invalidOpenings(): array
    {
        return [
            [['identity_verified' => false], 'identity_verified'], [['patient_version' => null], 'patient_version'],
            [['patient_id' => -1], 'patient_id'], [['care_setting' => 'WARD'], 'care_setting'],
            [['visit_type' => 'Invented'], 'visit_type'], [['opened_by' => 999], 'opened_by'],
            [['opened_at' => '2020-01-01T00:00:00Z'], 'opened_at'], [['status' => 'closed'], 'status'],
            [['category_id' => 99], 'category_id'], [['version' => 1], 'version'],
        ];
    }

    public function test_arrival_rejects_different_identity_stale_patient_stale_booking_and_terminal_booking(): void
    {
        $booking = Appointment::factory()->registered($this->patient)->create();
        $other = Patient::factory()->create();
        $data = $this->opening(); unset($data['visit_type']);
        $data += ['version' => 1, 'reason' => 'Private identity verification'];
        $url = '/api/v1/appointments/'.$booking->id.'/arrive';
        $this->assertApiFailure($this->postCommand($url, array_replace($data, ['patient_id' => $other->id])), 422, 'validation_failed', ['patient_id']);
        $this->assertApiFailure($this->postCommand($url, array_replace($data, ['patient_version' => 2])), 409, 'version_conflict');
        $this->assertApiFailure($this->postCommand($url, array_replace($data, ['version' => 2])), 409, 'version_conflict');
        $booking->forceFill(['status' => 'cancelled', 'version' => 2])->save();
        $this->assertApiFailure($this->postCommand($url, array_replace($data, ['version' => 2])), 409, 'appointment_terminal');
        $this->assertDatabaseCount('encounters', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
    }

    public function test_assignment_validation_and_expected_versions_reject_stale_or_unsafe_actions(): void
    {
        $id = $this->openVisit();
        $this->doctor->forceFill(['is_active' => false, 'version' => 2])->save();
        $this->assign($id)->assertUnprocessable();
        $this->doctor->forceFill(['is_active' => true, 'version' => 3])->save();
        $assignment = $this->assign($id)->assertOk()->json('data.current_assignment');
        $this->assertApiFailure($this->assign($id, 1), 409, 'version_conflict');
        $this->assertApiFailure($this->assign($id, 2), 409, 'assignment_exists');
        $this->assertApiFailure($this->postCommand('/api/v1/encounters/'.$id.'/reassign', $this->transition(2, $assignment)
            + ['doctor_id' => $this->doctor->id, 'consultation_type' => 'General Consultation']), 409, 'assignment_unchanged');
        $this->signIn($this->doctor);
        $this->assertApiFailure($this->postCommand('/api/v1/encounters/'.$id.'/complete', $this->transition(2, $assignment)), 409, 'assignment_transition');
        $assignment['version'] = 99;
        $this->assertApiFailure($this->postCommand('/api/v1/encounters/'.$id.'/accept', $this->transition(2, $assignment)), 409, 'version_conflict');
        $this->assertDatabaseCount('doctor_assignments', 1);
        $this->assertSame(2, Encounter::findOrFail($id)->version);
    }

    public function test_idempotency_checks_payload_target_current_permission_patient_state_and_latest_visit_version(): void
    {
        $key = ['Idempotency-Key' => 'walk-in-confirmation-0001'];
        $data = $this->opening();
        $id = $this->postCommand('/api/v1/encounters', $data, $key)->assertCreated()->json('data.id');
        $this->postCommand('/api/v1/encounters', $data, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertApiFailure($this->postCommand('/api/v1/encounters', array_replace($data, ['care_setting' => 'IPD']), $key), 409, 'idempotency_conflict');
        $this->assign($id)->assertOk();
        $this->assertApiFailure($this->postCommand('/api/v1/encounters', $data, $key), 409, 'idempotency_outdated');
        $this->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->postCommand('/api/v1/encounters', $data, $key)->assertNotFound();
        $this->getJson('/api/v1/encounters/'.$id)->assertNotFound();
        $this->getJson('/api/v1/encounters/'.$id.'/history')->assertNotFound();
        $this->getJson('/api/v1/encounters/'.$id.'/assignments')->assertNotFound();
        $this->getJson('/api/v1/queues')->assertOk()->assertJsonCount(0, 'data');
        $role = Role::where('code', 'frontdesk')->sole();
        $role->permissions()->detach(Permission::where('code', 'encounters.arrive')->sole()->id);
        $this->postCommand('/api/v1/encounters', $data, $key)->assertForbidden();
    }

    public function test_read_only_roles_and_operational_grants_do_not_allow_impersonating_a_clinician(): void
    {
        $id = $this->openVisit();
        $assignment = $this->assign($id)->assertOk()->json('data.current_assignment');
        $this->grantRolePermissions('frontdesk', ['encounters.progress']);
        $this->postCommand('/api/v1/encounters/'.$id.'/accept', $this->transition(2, $assignment))->assertForbidden();
        $other = $this->createStaffWithRole('doctor');
        $this->grantRolePermissions('doctor', ['encounters.view_any']);
        $this->signIn($other);
        $this->getJson('/api/v1/encounters/'.$id)->assertOk();
        $this->postCommand('/api/v1/encounters/'.$id.'/accept', $this->transition(2, $assignment))->assertForbidden();
        $this->signIn($this->doctor);
        $this->postCommand('/api/v1/encounters/'.$id.'/cancel', ['version' => 2, 'reason' => 'Private visit reason'])->assertForbidden();
        $admin = $this->createStaffWithRole('admin');
        $this->signIn($admin);
        $this->getJson('/api/v1/queues')->assertForbidden();
        $this->getJson('/api/v1/audit-events?subject_type=encounter&subject_id='.$id.'&action=encounter.assigned')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.safe_summary.version', 2)->assertJsonMissingPath('data.0.safe_summary.snapshot');
    }

    #[DataProvider('arrivalFailures')]
    public function test_arrival_booking_history_visit_audit_and_receipt_are_atomic(string $table, string $verb): void
    {
        $booking = Appointment::factory()->create();
        $data = $this->opening(); unset($data['visit_type']);
        $data += ['version' => 1, 'reason' => 'Private identity verification'];
        $hit = $this->failNextWriteTo($table, $verb);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/appointments/'.$booking->id.'/arrive', $data));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertNoSuccessfulAudit('appointment.attended');
        $this->assertNoSuccessfulAudit('encounter.opened');
    }

    public static function arrivalFailures(): array
    {
        return [['appointments', 'update'], ['appointment_revisions', 'insert'], ['encounters', 'insert'],
            ['encounter_revisions', 'insert'], ['audit_events', 'insert'], ['idempotency_requests', 'update']];
    }

    #[DataProvider('assignmentFailures')]
    public function test_assignment_and_reassignment_failures_leave_original_clinician_current(string $table, string $verb): void
    {
        $id = $this->openVisit();
        $assignment = $this->assign($id)->assertOk()->json('data.current_assignment');
        $other = $this->createStaffWithRole('doctor');
        $hit = $this->failNextWriteTo($table, $verb);
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/encounters/'.$id.'/reassign',
            $this->transition(2, $assignment) + ['doctor_id' => $other->id, 'consultation_type' => 'General Consultation']));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
        $this->assertSame($this->doctor->id, Encounter::findOrFail($id)->currentAssignment->doctor_id);
    }

    public static function assignmentFailures(): array
    {
        return [['doctor_assignments', 'update'], ['doctor_assignments', 'insert'], ['encounters', 'update'],
            ['encounter_revisions', 'insert'], ['audit_events', 'insert'], ['idempotency_requests', 'update']];
    }

    public function test_active_visit_blocks_independent_merge_approval_until_care_is_resolved(): void
    {
        $id = $this->openVisit();
        $this->grantRolePermissions('frontdesk', ['patients.merges.request', 'patients.merges.approve']);
        $target = Patient::factory()->create();
        $proposal = $this->postCommand('/api/v1/patients/'.$this->patient->id.'/merge-requests', ['version' => 1,
            'target_patient_id' => $target->id, 'target_version' => 1, 'reason' => 'Private identity review', 'current_password' => self::STAFF_TEST_PASSWORD])
            ->assertCreated()->json('data.record_id');
        $reviewer = $this->createStaffWithRole('frontdesk');
        $this->signIn($reviewer);
        $data = ['version' => 2, 'request_version' => 1, 'reason' => 'Private independent review', 'current_password' => self::STAFF_TEST_PASSWORD];
        $this->assertApiFailure($this->postCommand('/api/v1/patients/'.$this->patient->id.'/merge-requests/'.$proposal.'/approve', $data), 409, 'merge_encounters_pending');
        $this->postCommand('/api/v1/encounters/'.$id.'/cancel', ['version' => 1, 'reason' => 'Resolved visit before merge'])->assertOk();
        $this->postCommand('/api/v1/patients/'.$this->patient->id.'/merge-requests/'.$proposal.'/approve', $data)->assertOk();
        $this->getJson('/api/v1/encounters/'.$id)->assertNotFound();
        $this->assertSame($this->patient->id, Encounter::findOrFail($id)->patient_id);
    }

    public function test_history_is_append_only_and_rejects_forged_identity_or_version(): void
    {
        $id = $this->openVisit();
        $revision = EncounterRevision::where('encounter_id', $id)->sole();
        $this->assertTrue($revision->encounter->is(Encounter::findOrFail($id)));
        $this->assertTrue($revision->actor->is($this->registrar));
        foreach (['update', 'delete', 'insert'] as $operation) {
            try {
                $this->assertTablesUnchanged($this->tables(), function () use ($revision, $operation): void {
                    $query = DB::table('encounter_revisions')->where('id', $revision->id);
                    if ($operation === 'update') {
                        $query->update(['reason' => 'Rewrite private history']);
                    } elseif ($operation === 'delete') {
                        $query->delete();
                    } else {
                        $row = $revision->getAttributes(); unset($row['id']);
                        $row['version'] = 2;
                        DB::table('encounter_revisions')->insert($row);
                    }
                });
                $this->fail('Private visit history must remain valid and immutable.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_database_errors_log_only_generic_encounter_message(): void
    {
        Log::spy();
        $hit = false;
        DB::listen(function (QueryExecuted $event) use (&$hit): void {
            if (! $hit && preg_match('/^insert into [`"]encounters[`"]/i', $event->sql) === 1) {
                $hit = true;
                throw new QueryException('testing', 'Private SQL', ['Private-Patient'], new PDOException('Private failure'));
            }
        });
        $this->assertApiFailure($this->postCommand('/api/v1/encounters', $this->opening()), 500, 'server_error');
        $this->assertTrue($hit);
        Log::shouldHaveReceived('error')->once()->with('An encounter database operation failed.');
    }

    public function test_options_and_minimal_assignment_roster_need_explicit_queue_permissions(): void
    {
        $this->getJson('/api/v1/encounters/options')->assertOk()->assertJsonPath('data.timezone', 'Africa/Lagos')
            ->assertJsonPath('data.current_clinicians_per_visit', 1);
        $this->getJson('/api/v1/encounters/clinicians')->assertOk()->assertJsonPath('data.0.id', $this->doctor->id)
            ->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.staff_code');
        $this->signIn($this->doctor);
        $this->getJson('/api/v1/encounters/options')->assertOk();
        $this->getJson('/api/v1/encounters/clinicians')->assertForbidden();
    }

    public function test_guest_inactive_and_revoked_patient_view_cannot_read_or_retry_saved_arrival(): void
    {
        $key = ['Idempotency-Key' => 'arrival-access-check-0001'];
        $data = $this->opening();
        $id = $this->postCommand('/api/v1/encounters', $data, $key)->assertCreated()->json('data.id');
        $role = Role::where('code', 'frontdesk')->sole();
        $role->permissions()->detach(Permission::where('code', 'patients.view')->sole()->id);
        $this->getJson('/api/v1/queues')->assertForbidden();
        $this->postCommand('/api/v1/encounters', $data, $key)->assertForbidden();
        $this->grantRolePermissions('frontdesk', ['patients.view']);
        $this->registrar->forceFill(['is_active' => false, 'version' => 2])->save();
        $this->signIn($this->registrar->fresh());
        $this->getJson('/api/v1/encounters/'.$id)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/queues')->assertUnauthorized();
    }

    #[DataProvider('progressFailures')]
    public function test_acceptance_and_completion_roll_back_parent_assignment_and_private_history(string $action, string $table): void
    {
        $id = $this->openVisit();
        $assignment = $this->assign($id)->assertOk()->json('data.current_assignment');
        $this->signIn($this->doctor);
        if ($action === 'complete') {
            $this->postCommand('/api/v1/encounters/'.$id.'/accept', $this->transition(2, $assignment))->assertOk();
            $assignment['version'] = 2;
        }
        $hit = $this->failNextWriteTo($table, $table === 'encounter_revisions' ? 'insert' : 'update');
        $response = $this->assertTablesUnchanged($this->tables(), fn () => $this->postCommand('/api/v1/encounters/'.$id.'/'.$action,
            $this->transition($action === 'accept' ? 2 : 3, $assignment)));
        $this->assertTrue($hit());
        $this->assertApiFailure($response, 500, 'server_error');
    }

    public static function progressFailures(): array
    {
        return [['accept', 'encounters'], ['accept', 'doctor_assignments'], ['accept', 'encounter_revisions'],
            ['complete', 'encounters'], ['complete', 'doctor_assignments'], ['complete', 'encounter_revisions']];
    }

    public function test_live_clock_reassignment_and_completion_share_consistent_microsecond_event_times(): void
    {
        $this->travelBack();
        $id = $this->openVisit();
        $old = $this->assign($id)->assertOk()->json('data.current_assignment');
        $other = $this->createStaffWithRole('doctor');
        $current = $this->postCommand('/api/v1/encounters/'.$id.'/reassign', $this->transition(2, $old)
            + ['doctor_id' => $other->id, 'consultation_type' => 'General Consultation'])->assertOk()->json('data.current_assignment');
        $this->assertSame(DoctorAssignment::findOrFail($old['id'])->ended_at->toISOString(), $current['assigned_at']);
        $this->signIn($other);
        $this->postCommand('/api/v1/encounters/'.$id.'/accept', $this->transition(3, $current))->assertOk();
        $current['version'] = 2;
        $this->postCommand('/api/v1/encounters/'.$id.'/complete', $this->transition(4, $current))->assertOk();
        $this->assertSame(Encounter::findOrFail($id)->closed_at->toISOString(), DoctorAssignment::findOrFail($current['id'])->ended_at->toISOString());
    }

    public function test_visit_and_assignment_resources_show_clinician_names_without_directory_or_audit_disclosure(): void
    {
        $doctor = $this->createStaffWithRole('doctor', ['first_name' => 'Visit Clinician Display Sentinel',
            'last_name' => 'Synthetic', 'email' => 'private.visit.clinician@example.test', 'staff_code' => 'DISPLAY-DOCTOR-2']);
        $id = $this->openVisit();
        $this->assign($id, doctor: $doctor)->assertOk()->assertJsonPath('data.current_assignment.doctor_name', $doctor->name)
            ->assertJsonMissingPath('data.current_assignment.doctor')->assertJsonMissingPath('data.current_assignment.email');
        $this->getJson('/api/v1/queues')->assertOk()->assertJsonPath('data.0.current_assignment.doctor_name', $doctor->name);
        $this->getJson('/api/v1/encounters/'.$id)->assertOk()->assertJsonPath('data.current_assignment.doctor_name', $doctor->name);
        $this->getJson('/api/v1/encounters/'.$id.'/assignments')->assertOk()->assertJsonPath('data.0.doctor_name', $doctor->name)
            ->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.staff_code');
        $this->getJson('/api/v1/encounters/'.$id.'/history')->assertOk()->assertJsonMissingPath('data.1.snapshot.assignments.0.doctor_name');
        $this->assertAuditExcludes([$doctor->name, $doctor->email, $doctor->staff_code]);
        $this->getJson('/api/v1/staff/'.$doctor->id)->assertNotFound();
        $other = $this->createStaffWithRole('doctor');
        $this->signIn($other);
        $this->getJson('/api/v1/encounters/'.$id)->assertNotFound();
    }

    private function opening(): array
    {
        return ['patient_id' => $this->patient->id, 'patient_version' => $this->patient->fresh()->version,
            'identity_verified' => true, 'care_setting' => 'OPD', 'visit_type' => 'General Consultation'];
    }

    private function openVisit(): int
    {
        return $this->postCommand('/api/v1/encounters', $this->opening())->assertCreated()->json('data.id');
    }

    private function assign(int $id, int $version = 1, ?User $doctor = null): \Illuminate\Testing\TestResponse
    {
        return $this->postCommand('/api/v1/encounters/'.$id.'/assign', ['version' => $version, 'reason' => 'Private visit reason',
            'doctor_id' => ($doctor ?? $this->doctor)->id, 'consultation_type' => 'General Consultation']);
    }

    private function transition(int $version, array $assignment): array
    {
        return ['version' => $version, 'assignment_id' => $assignment['id'], 'assignment_version' => $assignment['version'], 'reason' => 'Private visit reason'];
    }

    private function signIn(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
    }

    private function tables(): array
    {
        return ['patients', 'appointments', 'appointment_revisions', 'encounters', 'doctor_assignments', 'encounter_revisions', 'idempotency_requests'];
    }
}
