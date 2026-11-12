<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\Appointment;
use App\Models\AppointmentFollowUp;
use App\Models\AppointmentRevision;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AppointmentBookings
{
    public const COMMANDS = ['api.v1.appointments.store', 'api.v1.appointments.update',
        'api.v1.appointments.confirm', 'api.v1.appointments.cancel', 'api.v1.appointments.no-show'];

    public const FIELDS = ['first_name', 'last_name', 'phone', 'starts_at', 'ends_at', 'booking_channel',
        'visit_type', 'specialist_name', 'specialist_email', 'doctor_id'];

    public function __construct(private AuditTrail $audit, private WriteTransactions $transactions) {}

    public static function isCommand(string $route): bool
    {
        return in_array($route, self::COMMANDS, true);
    }

    public function authorize(Request $request, User $actor): void
    {
        Gate::forUser($actor)->authorize('appointments.view');
        $create = $request->route()->getName() === 'api.v1.appointments.store';
        Gate::forUser($actor)->authorize($create ? 'appointments.create' : 'appointments.update');
        if (! $create) {
            $record = Appointment::query()->visibleTo($actor)->lockForUpdate()->findOrFail($request->route('appointment'));
            $request->attributes->set('appointment_record', $record);
        }
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Appointment
    {
        return $this->locked($actor, function (User $actor) use ($data): Appointment {
            Gate::forUser($actor)->authorize('appointments.create');
            $patientId = isset($data['patient_id']) ? (int) $data['patient_id'] : null;
            if ($patientId !== null) {
                Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($patientId);
            }
            $this->clinician($data['doctor_id'] ?? null);
            $booking = new Appointment;
            $booking->forceFill($this->fields($data) + ['patient_id' => $patientId, 'booked_by' => $actor->id]);
            $previous = null;
            if (($data['follow_up_of_id'] ?? null) !== null) {
                $previous = Appointment::query()->visibleTo($actor)->lockForUpdate()->findOrFail($data['follow_up_of_id']);
                if ($previous->status !== 'attended' || $patientId === null || $previous->patient_id !== (int) $patientId
                    || $booking->starts_at->lessThanOrEqualTo($previous->starts_at)) {
                    throw ValidationException::withMessages(['follow_up_of_id' => ['Choose an earlier attended booking for the same registered patient.']]);
                }
            }
            $this->available($booking);
            $booking->save();
            if ($previous !== null) {
                $link = new AppointmentFollowUp;
                $link->forceFill(['appointment_id' => $booking->id, 'previous_appointment_id' => $previous->id,
                    'created_by' => $actor->id, 'created_at' => now('UTC')])->save();
            }
            $this->record($booking, $actor, 'created');

            return $booking;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data, string $action = 'update'): Appointment
    {
        return $this->locked($actor, function (User $actor) use ($id, $data, $action): Appointment {
            Gate::forUser($actor)->authorize('appointments.update');
            $booking = Appointment::query()->visibleTo($actor)->lockForUpdate()->findOrFail($id);
            if ($booking->version !== (int) $data['version']) {
                throw new WriteConflict('version_conflict', 'This booking has changed. Reload it before saving again.');
            }
            if (! in_array($booking->status, ['booked', 'confirmed'], true)) {
                throw new WriteConflict('appointment_terminal', 'This booking is already attended, cancelled or marked no-show. Create a new booking if needed.');
            }
            $operation = $action;
            if ($action === 'update') {
                $booking->forceFill($this->fields($data));
                $operation = $booking->isDirty(['starts_at', 'ends_at']) ? 'rescheduled' : 'updated';
                $this->clinician($booking->doctor_id);
                $this->validInterval($booking);
                $previous = $booking->followUpLink?->previousAppointment;
                if ($previous !== null && $booking->starts_at->lessThanOrEqualTo($previous->starts_at)) {
                    throw ValidationException::withMessages(['starts_at' => ['A follow-up must be after its original attended booking.']]);
                }
                $this->available($booking);
            } else {
                $target = match ($action) {
                    'confirm' => 'confirmed', 'cancel' => 'cancelled', 'no-show' => 'no_show',
                    default => throw new \LogicException('Unknown booking action.'),
                };
                if (! $booking->canTransitionTo($target)) {
                    throw new WriteConflict('appointment_transition', 'This booking cannot make that transition. Reload it before choosing another action.');
                }
                if ($action === 'confirm') {
                    $this->clinician($booking->doctor_id);
                    $this->validInterval($booking);
                    $this->available($booking);
                }
                if ($action === 'no-show') {
                    $this->validInterval($booking);
                    if (now('UTC')->lessThan($booking->ends_at)) {
                        throw new WriteConflict('appointment_not_due', 'Wait until the booking interval has ended before marking a no-show.');
                    }
                }
                $booking->status = $target;
                $operation = $target;
            }
            $booking->version++;
            $booking->save();
            $this->record($booking, $actor, $operation, $data['reason']);

            return $booking;
        });
    }

    /** @return array<string, mixed> */
    public static function snapshot(Appointment $booking): array
    {
        return $booking->only(['id', 'first_name', 'last_name', 'phone', 'booking_channel', 'visit_type',
            'specialist_name', 'specialist_email', 'status', 'patient_id', 'doctor_id', 'booked_by', 'version']) + [
            'starts_at' => $booking->starts_at->toISOString(), 'ends_at' => $booking->ends_at?->toISOString(),
            'follow_up_of_id' => $booking->followUpLink?->previous_appointment_id,
            'created_at' => $booking->created_at?->toISOString(), 'updated_at' => $booking->updated_at?->toISOString(),
        ];
    }

    private function record(Appointment $booking, User $actor, string $operation, ?string $reason = null): void
    {
        $booking->load('followUpLink');
        $revision = new AppointmentRevision;
        $revision->forceFill(['appointment_id' => $booking->id, 'version' => $booking->version, 'operation' => $operation,
            'actor_id' => $actor->id, 'reason' => $reason, 'snapshot' => self::snapshot($booking), 'recorded_at' => now('UTC')])->save();
        $this->audit->record('appointment.'.$operation, $actor->id, 'appointment', $booking->id,
            ['version' => $booking->version, 'record_id' => $revision->id]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function fields(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(self::FIELDS));
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $fields)) {
                $fields[$field] = AppointmentTime::parse($fields[$field]);
            }
        }
        if (array_key_exists('doctor_id', $fields) && $fields['doctor_id'] !== null) {
            $fields['doctor_id'] = (int) $fields['doctor_id'];
        }

        return $fields;
    }

    private function clinician(?int $id): void
    {
        if ($id !== null && User::query()->where('is_active', true)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('code', 'doctor'))->lockForUpdate()->find($id) === null) {
            throw ValidationException::withMessages(['doctor_id' => ['Choose an active clinician from the booking roster.']]);
        }
    }

    private function validInterval(Appointment $booking): void
    {
        if ($booking->ends_at === null || $booking->ends_at->lessThanOrEqualTo($booking->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => ['Supply an end time after the start.']]);
        }
    }

    private function available(Appointment $booking): void
    {
        $this->validInterval($booking);
        if ($booking->doctor_id === null) {
            return;
        }
        $conflict = Appointment::query()->where('doctor_id', $booking->doctor_id)->whereIn('status', ['booked', 'confirmed'])
            ->when($booking->exists, fn (Builder $query): Builder => $query->whereKeyNot($booking->id))
            ->where('starts_at', '<', $booking->ends_at->format('Y-m-d H:i:s.u'))
            ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>', $booking->starts_at->format('Y-m-d H:i:s.u')))
            ->lockForUpdate()->first(['id']);
        if ($conflict !== null) {
            throw new WriteConflict('appointment_conflict', 'The clinician is unavailable for that interval. Choose another time or clinician.');
        }
    }

    /** @param Closure(User): Appointment $operation */
    private function locked(User $actor, Closure $operation): Appointment
    {
        return $this->transactions->run(function () use ($actor, $operation): Appointment {
            abort_if(Permission::query()->where('code', 'staff.manage')->lockForUpdate()->first() === null, 503);
            abort_if(Permission::query()->where('code', 'patients.create')->lockForUpdate()->first() === null, 503);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('appointments.view');

            return $operation($actor);
        });
    }
}
