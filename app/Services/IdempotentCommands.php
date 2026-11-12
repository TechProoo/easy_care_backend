<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Http\Requests\ProtectedFileRules;
use App\Http\Resources\PatientResource;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\ProtectedFileResource;
use App\Http\Resources\StaffManagementResource;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Http\Resources\EncounterResource;
use App\Models\Permission;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;
use stdClass;

class IdempotentCommands
{
    private const ROUTES = [
        'api.v1.staff-management.store', 'api.v1.staff-management.update',
        'api.v1.staff-management.password-reset', 'api.v1.files.store',
        'api.v1.patients.store', 'api.v1.patients.update',
    ];

    public function __construct(private WriteTransactions $transactions, private ProtectedFiles $files, private AuditTrail $audit, private PatientRecordManagement $records, private AppointmentBookings $bookings, private EncounterFlow $encounters) {}

    /** @param Closure(): JsonResponse $operation */
    public function run(Request $request, Closure $operation): JsonResponse
    {
        $route = $request->route()->getName();
        $recordWrite = PatientRecordManagement::isCommand($route);
        $appointmentWrite = AppointmentBookings::isCommand($route);
        $encounterWrite = EncounterFlow::isCommand($route);
        if (! in_array($route, self::ROUTES, true) && ! $recordWrite && ! $appointmentWrite && ! $encounterWrite) {
            throw new LogicException('A write route must define its command outcome and authorization.');
        }
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => ['Supply an Idempotency-Key header of 16 to 128 letters, digits, dots, colons, underscores or hyphens.']]);
        }
        $fileWrite = $route === 'api.v1.files.store';
        $patientWrite = str_starts_with($route, 'api.v1.patients.');
        $hash = $this->digest(json_encode($this->canonical([
            'target' => $encounterWrite ? ['appointment' => $request->route('appointment'), 'encounter' => $request->route('encounter')] : ($recordWrite ? ['patient' => $request->route('patient'), 'flag' => $request->route('flag'), 'merge_request' => $request->route('mergeRequest')]
                : ($appointmentWrite ? $request->route('appointment') : ($patientWrite ? $request->route('patient') : $request->route('user')?->id))),
            'data' => $request->except('current_password'),
        ]), JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key);

        try {
            return $this->transactions->run(function () use ($request, $operation, $route, $fileWrite, $patientWrite, $recordWrite, $appointmentWrite, $encounterWrite, $hash, $keyHash): JsonResponse {
                // Current staff writes share this lock with console grants and recovery.
                abort_if(Permission::query()->where('code', 'staff.manage')->lockForUpdate()->first() === null, 503);
                if ($patientWrite || $appointmentWrite || $encounterWrite) {
                    abort_if(Permission::query()->where('code', 'patients.create')->lockForUpdate()->first() === null, 503);
                }
                $requestActor = $request->user('web');
                abort_unless($requestActor instanceof User, 401);
                $actor = User::query()->lockForUpdate()->findOrFail($requestActor->id);
                $this->authorize($request, $actor, $fileWrite);
                $scope = ['user_id' => $actor->id, 'route' => $route, 'request_key' => $keyHash];
                $existing = DB::table('idempotency_requests')->where($scope)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $this->replay($request, $actor, $existing, $hash, $fileWrite);
                }
                $id = DB::table('idempotency_requests')->insertGetId($scope + [
                    'request_hash' => $hash, 'status' => 'processing',
                    'expires_at' => now('UTC')->addDay(), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
                $response = $operation();
                if (! $response->isSuccessful()) {
                    throw new LogicException('A failed command must throw before it can commit.');
                }
                $body = $response->getData(true);
                $resource = $route === 'api.v1.staff-management.password-reset' ? $body['data']['user'] : $body['data'];
                DB::table('idempotency_requests')->where('id', $id)->update([
                    'status' => 'completed', 'response_code' => $response->getStatusCode(),
                    'resource_type' => $encounterWrite ? 'encounter' : ($appointmentWrite ? 'appointment' : ($recordWrite ? 'patient_record' : ($patientWrite ? 'patient' : ($fileWrite ? 'file' : 'staff')))), 'resource_id' => $resource['id'],
                    'outcome_id' => $recordWrite ? $resource['record_id'] : null,
                    'resource_version' => $resource['version'] ?? null, 'updated_at' => now('UTC'),
                ]);
                $response->headers->set('Idempotency-Replayed', 'false');

                return $response;
            }, databaseOnly: ! $fileWrite);
        } catch (Throwable $exception) {
            $path = $request->attributes->get('private_upload_path');
            if ($fileWrite && is_string($path)) {
                $this->files->cleanupUnreferenced($path);
            }

            throw $exception;
        }
    }

    private function authorize(Request $request, User $actor, bool $fileWrite): void
    {
        abort_unless($actor->is_active, 403);
        if (EncounterFlow::isCommand($request->route()->getName())) {
            $this->encounters->authorize($request, $actor);

            return;
        }
        if (AppointmentBookings::isCommand($request->route()->getName())) {
            $this->bookings->authorize($request, $actor);

            return;
        }
        if (PatientRecordManagement::isCommand($request->route()->getName())) {
            $this->records->authorize($request, $actor);

            return;
        }
        if (str_starts_with($request->route()->getName(), 'api.v1.patients.')) {
            Gate::forUser($actor)->authorize('patients.view');
            $create = $request->route()->getName() === 'api.v1.patients.store';
            Gate::forUser($actor)->authorize($create ? 'patients.create' : 'patients.update');
            if (! $create) {
                $patient = Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($request->route('patient'));
                Gate::forUser($actor)->authorize('update', $patient);
                $request->attributes->set('patient_record', $patient);
            }

            return;
        }
        if ($fileWrite) {
            Gate::forUser($actor)->authorize('files.view_own');
            Gate::forUser($actor)->authorize('files.upload');

            return;
        }
        Gate::forUser($actor)->authorize('staff.manage');
        if ($request->route()->getName() === 'api.v1.staff-management.password-reset') {
            Gate::forUser($actor)->authorize('staff.reset_password');
        }
        $password = $request->input('current_password');
        if (! is_string($password) || strlen($password) > 1024 || ! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
        }
        $target = $request->route('user');
        if ($target instanceof User) {
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            abort_if($target->assignedPermissionCodes()->diff($actor->permissionCodes())->isNotEmpty(), 403);
        }
    }

    private function replay(Request $request, User $actor, stdClass $record, string $hash, bool $fileWrite): JsonResponse
    {
        if (! hash_equals($record->request_hash, $hash)) {
            throw new WriteConflict('idempotency_conflict', 'This submission key was already used with different data. Reload the record before starting another save.');
        }
        if ($record->status !== 'completed' || now('UTC')->greaterThanOrEqualTo($record->expires_at)) {
            throw new WriteConflict('idempotency_expired', 'This submission can no longer be confirmed automatically. Check the saved record before starting another submission.');
        }
        if ($request->route()->getName() === 'api.v1.staff-management.password-reset') {
            throw new WriteConflict('idempotency_completed', 'The recovery code was already issued and cannot be shown again. Reload the staff record and verify identity before issuing a replacement.');
        }
        $patientWrite = str_starts_with($request->route()->getName(), 'api.v1.patients.');
        $appointmentWrite = AppointmentBookings::isCommand($request->route()->getName());
        $encounterWrite = EncounterFlow::isCommand($request->route()->getName());
        if ($encounterWrite) {
            $visit = Encounter::query()->visibleTo($actor)->with(['patient', 'currentAssignment'])->lockForUpdate()->findOrFail($record->resource_id);
            if ($visit->version !== (int) $record->resource_version) {
                throw new WriteConflict('idempotency_outdated', 'The original save completed, but this visit has changed again. Reload its latest record.');
            }
            $response = (new EncounterResource($visit))->response();
        } elseif ($appointmentWrite) {
            $booking = Appointment::query()->visibleTo($actor)->with('followUpLink')->lockForUpdate()->findOrFail($record->resource_id);
            if ($booking->version !== (int) $record->resource_version) {
                throw new WriteConflict('idempotency_outdated', 'The original save completed, but this booking has changed again. Reload its latest record.');
            }
            $response = (new AppointmentResource($booking))->response();
        } elseif (PatientRecordManagement::isCommand($request->route()->getName())) {
            $response = $this->records->replay($request, $actor, $record);
        } elseif ($patientWrite) {
            $patient = Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($record->resource_id);
            if ($patient->version !== (int) $record->resource_version) {
                throw new WriteConflict('idempotency_outdated', 'The original save completed, but this patient record has changed again. Reload its latest record.');
            }
            $response = (new PatientResource($patient->load(['category', 'contacts', 'identifiers'])))->response();
        } elseif ($fileWrite) {
            $file = $this->files->owned($actor, (int) $record->resource_id);
            $response = (new ProtectedFileResource($file))->response();
        } else {
            $user = User::query()->lockForUpdate()->findOrFail($record->resource_id);
            abort_if($user->assignedPermissionCodes()->diff($actor->permissionCodes())->isNotEmpty(), 403);
            if ($user->version !== (int) $record->resource_version) {
                throw new WriteConflict('idempotency_outdated', 'The original save completed, but this account has changed again. Reload its latest record.');
            }
            $response = (new StaffManagementResource($user->load(['department', 'roles'])))->response();
        }
        $this->audit->record('command.replayed', $actor->id, $encounterWrite ? 'encounter' : ($appointmentWrite ? 'appointment' : ($patientWrite ? 'patient' : ($fileWrite ? 'file' : 'staff'))), (int) $record->resource_id);
        $response->setStatusCode(200);
        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            if (! $value->isValid() || $value->getSize() === 0 || $value->getSize() > (ProtectedFileRules::MAX_KILOBYTES * 1024)) {
                throw ValidationException::withMessages(['file' => ['Choose a valid non-empty file no larger than 10 MB.']]);
            }

            return ['name' => $value->getClientOriginalName(), 'size' => $value->getSize(),
                'sha256' => hash_file('sha256', $value->getRealPath())];
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonical(...), $value);
    }
}
