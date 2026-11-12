<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VisitFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrar = User::factory()->create();
        $this->doctor = User::factory()->create();
    }

    public function test_booking_can_precede_registration_and_retain_its_contact_snapshot(): void
    {
        $booking = $this->booking(['first_name' => 'Original', 'last_name' => 'Caller', 'phone' => '+2348012345678']);
        $this->assertNull($booking->patient_id);
        $this->assertNull($booking->doctor_id);
        $this->assertNull($booking->encounter);
        $this->assertSame(1, $booking->version);
        $patient = Patient::factory()->create();
        $booking->forceFill(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'version' => 2])->save();
        $patient->update(['first_name' => 'Changed', 'phone' => '+2348099999999']);
        $booking->refresh();
        $this->assertSame(['Original', 'Caller', '+2348012345678'], [$booking->first_name, $booking->last_name, $booking->phone]);
        $this->assertTrue($booking->patient->is($patient));
        $this->assertTrue($booking->doctor->is($this->doctor));
        $this->assertTrue($booking->bookedBy->is($this->registrar));
        $this->assertTrue($patient->appointments->sole()->is($booking));
        $this->assertTrue($this->doctor->doctorAppointments->sole()->is($booking));
        $this->assertTrue($this->registrar->bookedAppointments->sole()->is($booking));
    }

    public function test_booking_preserves_unicode_and_precise_schedule_without_requiring_a_slot_duration(): void
    {
        $booking = $this->booking(['first_name' => 'Zoë', 'last_name' => 'Adébáyọ̀',
            'starts_at' => '2026-11-11 10:00:00.123456', 'ends_at' => '2026-11-11 10:00:00.123457']);
        $booking->refresh();
        $this->assertSame('Zoë', $booking->first_name);
        $this->assertSame('Adébáyọ̀', $booking->last_name);
        $this->assertSame('123456', $booking->starts_at->format('u'));
        $this->assertSame('123457', $booking->ends_at->format('u'));
    }

    public function test_walk_in_visit_keeps_category_at_visit_and_relationships_are_bidirectional(): void
    {
        $patient = Patient::factory()->create();
        $visit = $this->visit(['patient_id' => $patient->id]);
        $category = $visit->category;
        $patient->forceFill(['category_id' => PatientCategory::factory()->create()->id])->save();
        $visit->refresh();
        $this->assertNull($visit->appointment);
        $this->assertTrue($visit->patient->is($patient));
        $this->assertTrue($visit->category->is($category));
        $this->assertTrue($category->encounters->sole()->is($visit));
        $this->assertTrue($patient->encounters->sole()->is($visit));
        $this->assertTrue($visit->openedBy->is($this->registrar));
        $this->assertTrue($this->registrar->openedEncounters->sole()->is($visit));
        $this->assertSame(1, $visit->version);
    }

    public function test_attended_booking_has_exactly_one_matching_patient_visit(): void
    {
        $patient = Patient::factory()->create();
        $booking = $this->booking(['patient_id' => $patient->id]);
        $booking->forceFill(['status' => 'attended', 'version' => 2])->save();
        $visit = Encounter::factory()->fromAppointment($booking)->create(['opened_by' => $this->registrar->id]);
        $this->assertTrue($visit->appointment->is($booking));
        $this->assertTrue($booking->fresh()->encounter->is($visit));
        $this->reject(fn () => Encounter::factory()->fromAppointment($booking)->create());
    }

    #[DataProvider('invalidBookingLinks')]
    public function test_visit_rejects_unlinked_wrong_patient_or_unattended_booking(string $case): void
    {
        $patient = Patient::factory()->create();
        $booking = $this->booking(['patient_id' => $case === 'unlinked' ? null : $patient->id]);
        if ($case === 'wrong patient') {
            $booking->forceFill(['status' => 'attended', 'version' => 2])->save();
        }
        $visitPatient = $case === 'wrong patient' ? Patient::factory()->create() : $patient;
        $this->reject(fn () => $this->visit(['appointment_id' => $booking->id, 'patient_id' => $visitPatient->id]));
    }

    public static function invalidBookingLinks(): array
    {
        return ['unlinked' => ['unlinked'], 'wrong patient' => ['wrong patient'], 'unattended' => ['unattended']];
    }

    #[DataProvider('appointmentTransitions')]
    public function test_allowed_appointment_transitions(string $from, string $to): void
    {
        $booking = $this->booking(['patient_id' => Patient::factory()->create()->id]);
        if ($from === 'confirmed') {
            $booking->forceFill(['status' => 'confirmed', 'version' => 2])->save();
        }
        $this->assertTrue($booking->canTransitionTo($to));
        $booking->forceFill(['status' => $to, 'version' => $booking->version + 1])->save();
        $this->assertSame($to, $booking->fresh()->status);
    }

    public static function appointmentTransitions(): array
    {
        $cases = [];
        foreach (Appointment::TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $cases[$from.' to '.$to] = [$from, $to];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidAppointments')]
    public function test_database_rejects_invalid_booking_rows(array $changes): void
    {
        $row = Appointment::factory()->make(['booked_by' => $this->registrar->id, 'starts_at' => '2026-11-11 10:00:00'])->getAttributes();
        $this->reject(fn () => DB::table('appointments')->insert(array_replace($row, $changes)));
    }

    public static function invalidAppointments(): array
    {
        return [
            'blank name' => [['first_name' => '  ']], 'blank surname' => [['last_name' => '']],
            'blank phone' => [['phone' => '']], 'long phone' => [['phone' => str_repeat('1', 33)]],
            'blank channel' => [['booking_channel' => '']], 'blank type' => [['visit_type' => ' ']],
            'status' => [['status' => 'unknown']], 'case sensitive status' => [['status' => 'BOOKED']],
            'version zero' => [['version' => 0]], 'version starts one' => [['version' => 2]],
            'end before start' => [['ends_at' => '2026-11-11 09:00:00']],
            'end equals start' => [['ends_at' => '2026-11-11 10:00:00']],
            'missing patient' => [['patient_id' => 99999999]], 'missing doctor' => [['doctor_id' => 99999999]],
            'missing booker' => [['booked_by' => 99999999]], 'insert terminal' => [['status' => 'cancelled']],
        ];
    }

    #[DataProvider('protectedAppointmentChanges')]
    public function test_booking_identity_version_and_terminal_state_are_protected(string $case): void
    {
        $booking = $this->booking(['patient_id' => Patient::factory()->create()->id]);
        $changes = ['version' => 2];
        if ($case === 'terminal') {
            $booking->forceFill(['status' => 'cancelled', 'version' => 2])->save();
            $changes = ['status' => 'booked', 'version' => 3];
            $this->assertFalse($booking->canTransitionTo('booked'));
        } else {
            $changes += match ($case) {
                'unlink' => ['patient_id' => null], 'reparent' => ['patient_id' => Patient::factory()->create()->id],
                'booker' => ['booked_by' => $this->doctor->id], 'version' => ['first_name' => 'Changed'],
                'id' => ['id' => 99999999],
            };
            if ($case === 'version') {
                $changes['version'] = 1;
            }
        }
        $this->reject(fn () => DB::table('appointments')->where('id', $booking->id)->update($changes));
    }

    public static function protectedAppointmentChanges(): array
    {
        return array_combine(['unlink', 'reparent', 'booker', 'version', 'id', 'terminal'],
            array_map(fn (string $case): array => [$case], ['unlink', 'reparent', 'booker', 'version', 'id', 'terminal']));
    }

    #[DataProvider('invalidEncounters')]
    public function test_database_rejects_invalid_visit_rows(array $changes): void
    {
        $row = Encounter::factory()->make(['opened_by' => $this->registrar->id, 'opened_at' => '2026-11-11 10:00:00'])->getAttributes();
        $this->reject(fn () => DB::table('encounters')->insert(array_replace($row, $changes)));
    }

    public static function invalidEncounters(): array
    {
        return [
            'status' => [['status' => 'invalid']], 'case setting' => [['care_setting' => 'opd']],
            'care setting' => [['care_setting' => 'WARD']], 'blank type' => [['visit_type' => '']],
            'missing patient' => [['patient_id' => 99999999]], 'missing category' => [['category_id' => 99999999]],
            'missing booker' => [['opened_by' => 99999999]], 'missing appointment' => [['appointment_id' => 99999999]],
            'version' => [['version' => 0]], 'initial version' => [['version' => 2]],
            'initial progress' => [['status' => 'in_progress']], 'initial closed' => [['status' => 'closed', 'closed_at' => '2026-11-11 11:00:00']],
            'open cannot end' => [['closed_at' => '2026-11-11 11:00:00']],
        ];
    }

    public function test_visit_category_must_match_patient_at_creation(): void
    {
        $other = PatientCategory::factory()->create();
        $patient = Patient::factory()->create();
        $this->reject(fn () => $this->visit(['patient_id' => $patient->id, 'category_id' => $other->id]));
    }

    #[DataProvider('protectedVisitChanges')]
    public function test_visit_identity_snapshot_and_lifecycle_are_protected(string $case): void
    {
        $visit = $this->visit();
        $changes = ['version' => 2] + match ($case) {
            'patient' => ['patient_id' => Patient::factory()->create()->id],
            'category' => ['category_id' => PatientCategory::factory()->create()->id],
            'appointment' => ['appointment_id' => $this->booking()->id],
            'opener' => ['opened_by' => $this->doctor->id], 'opened' => ['opened_at' => '2000-01-01 00:00:00'],
            'skip care' => ['status' => 'closed', 'closed_at' => now()->addHour()],
            'end before open' => ['status' => 'cancelled', 'closed_at' => '2000-01-01 00:00:00'],
            'missing end' => ['status' => 'cancelled'], 'version' => ['visit_type' => 'Follow-Up Appointment'],
        };
        if ($case === 'version') {
            $changes['version'] = 1;
        }
        $this->reject(fn () => DB::table('encounters')->where('id', $visit->id)->update($changes));
    }

    public static function protectedVisitChanges(): array
    {
        $cases = ['patient', 'category', 'appointment', 'opener', 'opened', 'skip care', 'end before open', 'missing end', 'version'];

        return array_combine($cases, array_map(fn (string $case): array => [$case], $cases));
    }

    public function test_single_current_assignment_reassignment_acceptance_and_completion_retain_history(): void
    {
        $visit = $this->visit(['opened_at' => '2026-11-11 10:00:00.123456']);
        $first = $this->assignment($visit, ['assigned_at' => '2026-11-11 10:01:00.123456']);
        $this->assertTrue($first->doctor->is($this->doctor));
        $this->assertTrue($first->assignedBy->is($this->registrar));
        $this->assertTrue($first->encounter->is($visit));
        $this->assertTrue($visit->currentAssignment->is($first));
        $this->reject(fn () => $this->assignment($visit));
        $this->endAssignment($first, 'reassigned', '2026-11-11 10:02:00.123456');
        $second = $this->assignment($visit, ['doctor_id' => $this->registrar->id, 'assigned_at' => '2026-11-11 10:02:00.123456']);
        $visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $this->assertTrue($second->canTransitionTo('accepted'));
        $second->forceFill(['status' => 'accepted', 'accepted_at' => '2026-11-11 10:03:00.123456', 'version' => 2])->save();
        $this->endAssignment($second, 'completed', '2026-11-11 10:04:00.123456');
        $visit->forceFill(['status' => 'closed', 'closed_at' => '2026-11-11 10:04:00.123456', 'version' => 3])->save();
        $visit->refresh();
        $this->assertNull($visit->currentAssignment);
        $this->assertSame([$first->id, $second->id], $visit->assignments->modelKeys());
        $this->assertSame(0, DoctorAssignment::current()->count());
        $this->assertTrue($this->doctor->doctorAssignments->sole()->is($first));
        $this->assertCount(2, $this->registrar->givenAssignments);
        $this->assertCount(2, $this->registrar->endedAssignments);
        $this->assertTrue($second->fresh()->endedBy->is($this->registrar));
        $this->assertSame('123456', $second->fresh()->accepted_at->format('u'));
        $this->assertSame('123456', $visit->opened_at->format('u'));
        $this->assertSame(3, $visit->version);
    }

    #[DataProvider('invalidAssignments')]
    public function test_database_rejects_invalid_assignment_rows(array $changes): void
    {
        $visit = $this->visit(['opened_at' => '2026-11-11 10:00:00']);
        $row = DoctorAssignment::factory()->make(['encounter_id' => $visit->id, 'doctor_id' => $this->doctor->id,
            'assigned_by' => $this->registrar->id, 'assigned_at' => '2026-11-11 10:01:00'])->getAttributes();
        $this->reject(fn () => DB::table('doctor_assignments')->insert(array_replace($row, $changes)));
    }

    public static function invalidAssignments(): array
    {
        return [
            'missing encounter' => [['encounter_id' => 99999999]], 'missing doctor' => [['doctor_id' => 99999999]],
            'missing assigner' => [['assigned_by' => 99999999]], 'version' => [['version' => 0]],
            'initial version' => [['version' => 2]], 'status' => [['status' => 'invalid']],
            'before visit' => [['assigned_at' => '2026-11-11 09:59:00']],
            'initial accepted' => [['status' => 'accepted', 'accepted_at' => '2026-11-11 10:02:00']],
            'unaccepted timestamp' => [['accepted_at' => '2026-11-11 10:02:00']],
            'unended actor' => [['ended_by' => 99999999]], 'unended reason' => [['end_reason' => 'Changed']],
            'unended timestamp' => [['ended_at' => '2026-11-11 10:02:00']],
        ];
    }

    #[DataProvider('invalidAssignmentUpdates')]
    public function test_assignment_attribution_versions_timestamps_and_transitions_are_guarded(string $case): void
    {
        $visit = $this->visit(['opened_at' => '2026-11-11 10:00:00']);
        $assignment = $this->assignment($visit, ['assigned_at' => '2026-11-11 10:01:00']);
        $changes = ['version' => 2] + match ($case) {
            'doctor' => ['doctor_id' => $this->registrar->id], 'assigner' => ['assigned_by' => $this->doctor->id],
            'visit' => ['encounter_id' => $this->visit()->id], 'type' => ['consultation_type' => 'Changed'],
            'assigned time' => ['assigned_at' => '2026-11-11 10:00:30'],
            'skip acceptance' => ['status' => 'completed', 'ended_by' => $this->registrar->id, 'ended_at' => '2026-11-11 10:03:00'],
            'accept open visit' => ['status' => 'accepted', 'accepted_at' => '2026-11-11 10:02:00'],
            'accept before assigned' => ['status' => 'accepted', 'accepted_at' => '2026-11-11 10:00:00'],
            'reassign missing reason' => ['status' => 'reassigned', 'ended_by' => $this->registrar->id, 'ended_at' => '2026-11-11 10:03:00'],
            'cancel missing end' => ['status' => 'cancelled', 'end_reason' => 'Cancelled', 'ended_by' => $this->registrar->id],
            'fabricate acceptance' => ['status' => 'reassigned', 'end_reason' => 'Transferred', 'ended_by' => $this->registrar->id,
                'ended_at' => '2026-11-11 10:03:00', 'accepted_at' => '2026-11-11 10:02:00'],
            'no transition' => [],
        };
        if (in_array($case, ['accept before assigned', 'fabricate acceptance'], true)) {
            $visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        }
        $this->reject(fn () => DB::table('doctor_assignments')->where('id', $assignment->id)->update($changes));
    }

    public static function invalidAssignmentUpdates(): array
    {
        $cases = ['doctor', 'assigner', 'visit', 'type', 'assigned time', 'skip acceptance', 'accept open visit',
            'accept before assigned', 'reassign missing reason', 'cancel missing end', 'fabricate acceptance', 'no transition'];

        return array_combine($cases, array_map(fn (string $case): array => [$case], $cases));
    }

    public function test_accepted_assignment_cannot_change_acceptance_time_or_cancel_and_cannot_overlap_history(): void
    {
        $visit = $this->visit(['opened_at' => '2026-11-11 10:00:00']);
        $assignment = $this->assignment($visit, ['assigned_at' => '2026-11-11 10:01:00']);
        $visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $assignment->forceFill(['status' => 'accepted', 'accepted_at' => '2026-11-11 10:02:00', 'version' => 2])->save();
        $terminal = ['status' => 'reassigned', 'ended_by' => $this->registrar->id, 'end_reason' => 'Transfer',
            'ended_at' => '2026-11-11 10:03:00', 'version' => 3];
        $this->reject(fn () => DB::table('doctor_assignments')->where('id', $assignment->id)->update($terminal + ['accepted_at' => '2026-11-11 10:02:30']));
        $this->reject(fn () => DB::table('doctor_assignments')->where('id', $assignment->id)->update(array_replace($terminal, ['status' => 'cancelled'])));
        $assignment->forceFill($terminal)->save();
        $this->reject(fn () => $this->assignment($visit, ['assigned_at' => '2026-11-11 10:02:59']));
        $this->reject(fn () => DB::table('doctor_assignments')->where('id', $assignment->id)->update(['end_reason' => 'Rewrite', 'version' => 4]));
        $this->assertFalse($assignment->canTransitionTo('assigned'));
    }

    public function test_acceptance_requires_the_next_version_and_ending_cannot_precede_acceptance(): void
    {
        $visit = $this->visit(['opened_at' => '2026-11-11 10:00:00']);
        $assignment = $this->assignment($visit, ['assigned_at' => '2026-11-11 10:01:00']);
        $visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $accepted = ['status' => 'accepted', 'accepted_at' => '2026-11-11 10:02:00'];
        foreach ([1, 3] as $version) {
            $this->reject(fn () => DB::table('doctor_assignments')->where('id', $assignment->id)->update($accepted + ['version' => $version]));
        }
        $assignment->forceFill($accepted + ['version' => 2])->save();
        $this->reject(fn () => DB::table('doctor_assignments')->where('id', $assignment->id)->update([
            'status' => 'completed', 'ended_at' => '2026-11-11 10:01:59', 'ended_by' => $this->registrar->id, 'version' => 3,
        ]));
    }

    public function test_visit_must_end_assignments_before_closure_and_cannot_reopen(): void
    {
        $visit = $this->visit(['opened_at' => '2026-11-11 10:00:00']);
        $assignment = $this->assignment($visit, ['assigned_at' => '2026-11-11 10:01:00']);
        $this->reject(fn () => DB::table('encounters')->where('id', $visit->id)->update(['status' => 'cancelled', 'closed_at' => '2026-11-11 10:02:00', 'version' => 2]));
        $this->endAssignment($assignment, 'cancelled', '2026-11-11 10:02:00');
        $this->reject(fn () => DB::table('encounters')->where('id', $visit->id)->update(['status' => 'cancelled', 'closed_at' => '2026-11-11 10:01:30', 'version' => 2]));
        $visit->forceFill(['status' => 'cancelled', 'closed_at' => '2026-11-11 10:02:00', 'version' => 2])->save();
        $this->assertFalse($visit->canTransitionTo('open'));
        $this->reject(fn () => DB::table('encounters')->where('id', $visit->id)->update(['status' => 'open', 'closed_at' => null, 'version' => 3]));
        $this->reject(fn () => $this->assignment($visit, ['assigned_at' => '2026-11-11 10:03:00']));
    }

    public function test_an_in_progress_visit_cannot_be_cancelled_or_reclassified(): void
    {
        $visit = $this->visit();
        $visit->forceFill(['status' => 'in_progress', 'version' => 2])->save();
        $this->reject(fn () => DB::table('encounters')->where('id', $visit->id)->update(['status' => 'cancelled', 'closed_at' => now()->addHour(), 'version' => 3]));
        $this->reject(fn () => DB::table('encounters')->where('id', $visit->id)->update(['care_setting' => 'IPD', 'version' => 3]));
    }

    #[DataProvider('visitTables')]
    public function test_visit_records_cannot_be_deleted(string $table): void
    {
        $id = match ($table) {
            'appointments' => $this->booking()->id, 'encounters' => $this->visit()->id,
            'doctor_assignments' => $this->assignment($this->visit())->id,
        };
        $this->reject(fn () => DB::table($table)->where('id', $id)->delete());
    }

    public static function visitTables(): array
    {
        return [['appointments'], ['encounters'], ['doctor_assignments']];
    }

    public function test_related_visit_and_assignment_changes_roll_back_together(): void
    {
        $visit = $this->visit();
        $first = $this->assignment($visit);
        try {
            $this->assertTablesUnchanged(['appointments', 'encounters', 'doctor_assignments'], function () use ($visit, $first): void {
                DB::transaction(function () use ($visit, $first): void {
                    $this->endAssignment($first, 'reassigned', $first->assigned_at->addMinute()->format('Y-m-d H:i:s.u'));
                    $this->assignment($visit, ['assigned_at' => $first->assigned_at->addMinute()]);
                    throw new RuntimeException('Synthetic failure after replacing an assignment.');
                });
            });
            $this->fail('The injected failure must be reached.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic failure after replacing an assignment.', $exception->getMessage());
        }
        $this->assertTrue($visit->fresh()->currentAssignment->is($first));
    }

    /** @param array<string, mixed> $attributes */
    private function booking(array $attributes = []): Appointment
    {
        return Appointment::factory()->create($attributes + ['booked_by' => $this->registrar->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function visit(array $attributes = []): Encounter
    {
        return Encounter::factory()->create($attributes + ['opened_by' => $this->registrar->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function assignment(Encounter $visit, array $attributes = []): DoctorAssignment
    {
        return DoctorAssignment::factory()->create($attributes + ['encounter_id' => $visit->id,
            'doctor_id' => $this->doctor->id, 'assigned_by' => $this->registrar->id]);
    }

    private function endAssignment(DoctorAssignment $assignment, string $status, string $endedAt): void
    {
        $assignment->forceFill(['status' => $status, 'ended_at' => $endedAt, 'ended_by' => $this->registrar->id,
            'end_reason' => 'Synthetic assignment outcome', 'version' => $assignment->version + 1])->save();
    }

    private function reject(callable $operation): void
    {
        try {
            $this->assertTablesUnchanged(['appointments', 'encounters', 'doctor_assignments'], $operation(...));
            $this->fail('The database must reject the invalid visit write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
