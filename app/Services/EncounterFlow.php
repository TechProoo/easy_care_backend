<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\Appointment;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\EncounterRevision;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EncounterFlow
{
    public const COMMANDS = ['api.v1.appointments.arrive', 'api.v1.encounters.store', 'api.v1.encounters.assign',
        'api.v1.encounters.reassign', 'api.v1.encounters.accept', 'api.v1.encounters.complete', 'api.v1.encounters.cancel'];

    public function __construct(private WriteTransactions $transactions, private AppointmentBookings $bookings, private AuditTrail $audit) {}

    public static function isCommand(string $route): bool
    {
        return in_array($route, self::COMMANDS, true);
    }

    public static function action(Request $request): string
    {
        return substr($request->route()->getName(), strrpos($request->route()->getName(), '.') + 1);
    }

    public function authorize(Request $request, User $actor): void
    {
        abort_unless($actor->is_active, 403);
        Gate::forUser($actor)->authorize('encounters.view');
        Gate::forUser($actor)->authorize('patients.view');
        $action = self::action($request);
        $permission = match ($action) {
            'store', 'arrive' => 'encounters.arrive', 'assign', 'reassign' => 'encounters.assign',
            'accept', 'complete' => 'encounters.progress', 'cancel' => 'encounters.cancel',
        };
        Gate::forUser($actor)->authorize($permission);
        if (! in_array($action, ['accept', 'complete'], true)) {
            Gate::forUser($actor)->authorize('encounters.view_any');
        }
        if ($action === 'arrive') {
            Gate::forUser($actor)->authorize('appointments.view');
            $booking = Appointment::query()->visibleTo($actor)->lockForUpdate()->findOrFail($request->route('appointment'));
            $request->attributes->set('appointment_record', $booking);
        } elseif ($action !== 'store') {
            $encounter = Encounter::query()->visibleTo($actor)->lockForUpdate()->findOrFail($request->route('encounter'));
            if (in_array($action, ['accept', 'complete'], true)) {
                abort_unless($actor->roles()->where('code', 'doctor')->exists(), 403);
                abort_unless($encounter->assignments()->where('doctor_id', $actor->id)
                    ->where(fn (Builder $query): Builder => $query->whereNull('ended_at')->orWhere('status', 'completed'))->exists(), 403);
            }
            $request->attributes->set('encounter_record', $encounter);
        }
    }

    /** @param array<string, mixed> $data */
    public function execute(Request $request, User $actor, array $data): Encounter
    {
        return $this->transactions->run(function () use ($request, $actor, $data): Encounter {
            abort_if(Permission::query()->where('code', 'staff.manage')->lockForUpdate()->first() === null, 503);
            abort_if(Permission::query()->where('code', 'patients.create')->lockForUpdate()->first() === null, 503);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $this->authorize($request, $actor);
            $action = self::action($request);
            if (in_array($action, ['store', 'arrive'], true)) {
                return $this->open($request, $actor, $data, $action === 'arrive');
            }
            $visit = $request->attributes->get('encounter_record');
            $this->version($visit->version, $data['version']);
            if (! in_array($visit->status, ['open', 'in_progress'], true)) {
                throw new WriteConflict('encounter_terminal', 'This visit is already completed or cancelled.');
            }
            $assignment = $visit->assignments()->current()->lockForUpdate()->first();
            $time = now('UTC');
            if ($time->lessThan($visit->opened_at) || ($assignment !== null && ($time->lessThan($assignment->assigned_at)
                || ($assignment->accepted_at !== null && $time->lessThan($assignment->accepted_at))))) {
                throw new WriteConflict('encounter_clock_conflict', 'The server time precedes an existing visit event. Check the server clock.');
            }
            if (in_array($action, ['reassign', 'accept', 'complete'], true)) {
                if ($assignment === null || $assignment->id !== (int) $data['assignment_id']) {
                    throw new WriteConflict('assignment_conflict', 'The current clinician assignment has changed. Reload this visit.');
                }
                $this->version($assignment->version, $data['assignment_version']);
            }
            if (in_array($action, ['accept', 'complete'], true) && $assignment->doctor_id !== $actor->id) {
                abort(403);
            }
            if (in_array($action, ['assign', 'reassign'], true)) {
                if ($action === 'assign' && $assignment !== null) {
                    throw new WriteConflict('assignment_exists', 'This visit already has a current clinician. Use reassignment.');
                }
                $doctor = User::query()->where('is_active', true)
                    ->whereHas('roles', fn (Builder $query): Builder => $query->where('code', 'doctor'))->lockForUpdate()->find($data['doctor_id']);
                if ($doctor === null) {
                    throw ValidationException::withMessages(['doctor_id' => ['Choose an active clinician from the roster.']]);
                }
                if ($assignment !== null) {
                    if ($assignment->doctor_id === $doctor->id) {
                        throw new WriteConflict('assignment_unchanged', 'Choose a different clinician for reassignment.');
                    }
                    $this->end($assignment, $actor, 'reassigned', $data['reason'], $time);
                }
                $lastEnd = $visit->assignments()->whereNotNull('ended_at')->max('ended_at');
                if ($lastEnd !== null && $time->lessThan($lastEnd)) {
                    throw new WriteConflict('encounter_clock_conflict', 'The server time precedes the previous assignment. Check the server clock.');
                }
                $new = new DoctorAssignment;
                $new->forceFill(['encounter_id' => $visit->id, 'doctor_id' => $doctor->id, 'assigned_by' => $actor->id,
                    'assigned_at' => $time, 'consultation_type' => $data['consultation_type']])->save();
            } elseif ($action === 'accept') {
                if ($assignment->status !== 'assigned') {
                    throw new WriteConflict('assignment_transition', 'Only a waiting assignment can be accepted.');
                }
                $visit->status = 'in_progress';
                $visit->version++;
                $visit->save();
                $assignment->forceFill(['status' => 'accepted', 'accepted_at' => $time, 'version' => $assignment->version + 1])->save();
            } elseif ($action === 'complete') {
                if ($assignment->status !== 'accepted' || $visit->status !== 'in_progress') {
                    throw new WriteConflict('assignment_transition', 'Accept the current assignment before completing the visit.');
                }
                $this->end($assignment, $actor, 'completed', $data['reason'], $time);
                $visit->forceFill(['status' => 'closed', 'closed_at' => $time]);
            } else {
                if ($visit->status !== 'open' || $visit->assignments()->whereNotNull('accepted_at')->exists()) {
                    throw new WriteConflict('encounter_care_started', 'An accepted visit must be completed by its current clinician.');
                }
                if ($assignment !== null) {
                    $this->end($assignment, $actor, 'cancelled', $data['reason'], $time);
                }
                $visit->forceFill(['status' => 'cancelled', 'closed_at' => $time]);
            }
            if ($action !== 'accept') {
                $visit->version++;
                $visit->save();
            }
            $operation = match ($action) { 'assign' => 'assigned', 'reassign' => 'reassigned', 'accept' => 'accepted',
                'complete' => 'completed', 'cancel' => 'cancelled' };
            $this->record($visit, $actor, $operation, $data['reason']);

            return $visit;
        });
    }

    /** @param array<string, mixed> $data */
    private function open(Request $request, User $actor, array $data, bool $arrival): Encounter
    {
        $booking = $arrival ? $request->attributes->get('appointment_record') : null;
        if ($booking !== null) {
            $this->version($booking->version, $data['version']);
            if (! in_array($booking->status, ['booked', 'confirmed'], true)) {
                throw new WriteConflict('appointment_terminal', 'This booking is already attended, cancelled or marked no-show.');
            }
            if ($booking->patient_id !== null && $booking->patient_id !== (int) $data['patient_id']) {
                throw ValidationException::withMessages(['patient_id' => ['The arrival must use the patient already linked to this booking.']]);
            }
        }
        $patient = Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($data['patient_id']);
        $this->version($patient->version, $data['patient_version']);
        if ($booking !== null) {
            $booking->forceFill(['patient_id' => $patient->id, 'status' => 'attended', 'version' => $booking->version + 1])->save();
            $this->bookings->record($booking, $actor, 'attended', $data['reason']);
        }
        $visit = new Encounter;
        $visit->forceFill(['patient_id' => $patient->id, 'appointment_id' => $booking?->id, 'category_id' => $patient->category_id,
            'opened_by' => $actor->id, 'opened_at' => now('UTC'), 'care_setting' => $data['care_setting'],
            'visit_type' => $booking?->visit_type ?? $data['visit_type']])->save();
        $this->record($visit, $actor, 'opened');

        return $visit;
    }

    private function end(DoctorAssignment $assignment, User $actor, string $status, string $reason, CarbonInterface $time): void
    {
        $assignment->forceFill(['status' => $status, 'ended_at' => $time, 'ended_by' => $actor->id,
            'end_reason' => $reason, 'version' => $assignment->version + 1])->save();
    }

    private function version(int $current, mixed $expected): void
    {
        if ($current !== (int) $expected) {
            throw new WriteConflict('version_conflict', 'This record has changed. Reload it before trying again.');
        }
    }

    /** @return array<string, mixed> */
    public static function snapshot(Encounter $visit): array
    {
        $visit->load('assignments');

        return $visit->only(['id', 'patient_id', 'appointment_id', 'category_id', 'opened_by', 'visit_type', 'care_setting', 'status', 'version']) + [
            'opened_at' => $visit->opened_at->toISOString(), 'closed_at' => $visit->closed_at?->toISOString(),
            'assignments' => $visit->assignments->map(fn (DoctorAssignment $assignment): array => self::assignment($assignment))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function assignment(DoctorAssignment $assignment): array
    {
        return $assignment->only(['id', 'doctor_id', 'assigned_by', 'ended_by', 'consultation_type', 'status', 'version']) + [
            'assigned_at' => $assignment->assigned_at->toISOString(), 'accepted_at' => $assignment->accepted_at?->toISOString(),
            'ended_at' => $assignment->ended_at?->toISOString(),
        ];
    }

    private function record(Encounter $visit, User $actor, string $operation, ?string $reason = null): void
    {
        $revision = new EncounterRevision;
        $revision->forceFill(['encounter_id' => $visit->id, 'version' => $visit->version, 'operation' => $operation,
            'actor_id' => $actor->id, 'reason' => $reason, 'snapshot' => self::snapshot($visit), 'recorded_at' => now('UTC')])->save();
        $this->audit->record('encounter.'.$operation, $actor->id, 'encounter', $visit->id,
            ['version' => $visit->version, 'record_id' => $revision->id]);
    }
}
