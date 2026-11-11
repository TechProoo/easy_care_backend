<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Http\Requests\ProtectedFileRules;
use App\Http\Resources\ProtectedFileResource;
use App\Http\Resources\StaffManagementResource;
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
use stdClass;
use Throwable;

class IdempotentCommands
{
    private const ROUTES = [
        'api.v1.staff-management.store', 'api.v1.staff-management.update',
        'api.v1.staff-management.password-reset', 'api.v1.files.store',
    ];

    public function __construct(private WriteTransactions $transactions, private ProtectedFiles $files, private AuditTrail $audit) {}

    /** @param Closure(): JsonResponse $operation */
    public function run(Request $request, Closure $operation): JsonResponse
    {
        $route = $request->route()->getName();
        if (! in_array($route, self::ROUTES, true)) {
            throw new LogicException('A write route must define its command outcome and authorization.');
        }
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => ['Supply an Idempotency-Key header of 16 to 128 letters, digits, dots, colons, underscores or hyphens.']]);
        }
        $fileWrite = $route === 'api.v1.files.store';
        $hash = $this->digest(json_encode($this->canonical([
            'target' => $request->route('user')?->id, 'data' => $request->except('current_password'),
        ]), JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key);

        try {
            return $this->transactions->run(function () use ($request, $operation, $route, $fileWrite, $hash, $keyHash): JsonResponse {
                // Current staff writes share this lock with console grants and recovery.
                abort_if(Permission::query()->where('code', 'staff.manage')->lockForUpdate()->first() === null, 503);
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
                    'resource_type' => $fileWrite ? 'file' : 'staff', 'resource_id' => $resource['id'],
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
        if ($fileWrite) {
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
        $this->audit->record('command.replayed', $actor->id, $fileWrite ? 'file' : 'staff', (int) $record->resource_id);
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
