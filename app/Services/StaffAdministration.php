<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffAdministration
{
    public function __construct(private AuditTrail $audit) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): User
    {
        return $this->locked(function () use ($actor, $data): User {
            $actor = $this->actor($actor, $data['current_password']);
            Gate::forUser($actor)->authorize('create', User::class);
            $roles = $this->roles($actor, $data['role_codes']);
            $user = new User;
            $user->forceFill(collect($data)->only([
                'username', 'first_name', 'last_name', 'middle_name', 'email',
                'staff_code', 'department_id', 'is_active',
            ])->all());
            $user->password = Str::random(64);
            $user->save();
            $user->roles()->sync($roles);
            $this->audit->record('staff.created', $actor->id, 'staff', $user->id, [
                'role_codes' => $user->roles()->orderBy('code')->pluck('code')->all(),
                'department_id' => $user->department_id,
                'is_active' => $user->is_active,
                'version' => $user->version,
            ]);

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, User $target, array $data): User
    {
        return $this->locked(function () use ($actor, $target, $data): User {
            $actor = $this->actor($actor, $data['current_password']);
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('update', $target);
            $this->version($target, $data['version']);
            $this->manageable($actor, $target);
            $roles = $this->roles($actor, $data['role_codes']);
            $oldRoles = $target->roles()->pluck('roles.id')->sort()->values()->all();
            sort($roles);
            $accessChanged = $oldRoles !== $roles
                || $target->is_active !== (bool) $data['is_active']
                || $target->department_id !== ($data['department_id'] === null ? null : (int) $data['department_id']);

            if ($actor->id === $target->id && $accessChanged) {
                throw ValidationException::withMessages(['role_codes' => ['Another administrator must change your access controls.']]);
            }

            $previousRoles = $target->roles()->orderBy('code')->pluck('code')->all();
            $previousDepartment = $target->department_id;
            $previousActive = $target->is_active;
            $target->forceFill(collect($data)->only([
                'first_name', 'last_name', 'middle_name', 'email', 'staff_code', 'department_id', 'is_active',
            ])->all());
            $changedFields = array_keys($target->getDirty());
            if ($oldRoles !== $roles) {
                $changedFields[] = 'role_codes';
            }
            if ($target->isDirty('email')) {
                $target->email_verified_at = null;
            }
            $target->version++;
            $target->save();
            $target->roles()->sync($roles);
            DB::table('staff_password_resets')->where('user_id', $target->id)->delete();

            if ($accessChanged) {
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }

            $this->audit->record('staff.updated', $actor->id, 'staff', $target->id, [
                'changed_fields' => $changedFields,
                'previous_role_codes' => $previousRoles,
                'role_codes' => $target->roles()->orderBy('code')->pluck('code')->all(),
                'previous_department_id' => $previousDepartment,
                'department_id' => $target->department_id,
                'previous_is_active' => $previousActive,
                'is_active' => $target->is_active,
                'version' => $target->version,
                'sessions_revoked' => $accessChanged,
            ]);

            return $target;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array{user: User, token: string, expires_at: string}
     */
    public function issueReset(User $actor, User $target, array $data): array
    {
        return $this->locked(function () use ($actor, $target, $data): array {
            $actor = $this->actor($actor, $data['current_password']);
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            Gate::forUser($actor)->authorize('issuePasswordReset', $target);
            $this->version($target, $data['version']);
            $this->manageable($actor, $target);
            if (! $target->is_active || $actor->id === $target->id) {
                throw ValidationException::withMessages(['identity_verified' => [
                    'Recovery requires another active staff account. Use another administrator or the trusted server console for your own recovery.',
                ]]);
            }

            return $this->makeReset($target, $actor->id);
        });
    }

    /** @return array{user: User, token: string, expires_at: string} */
    public function issueConsoleReset(User $target): array
    {
        return $this->locked(function () use ($target): array {
            $target = User::query()->lockForUpdate()->findOrFail($target->id);
            abort_unless($target->is_active, 422);

            return $this->makeReset($target, null);
        });
    }

    /** @return array{user: User, token: string, expires_at: string} */
    private function makeReset(User $target, ?int $issuer): array
    {
        $token = bin2hex(random_bytes(24));
        $expires = now()->addMinutes(config('authorization.reset_minutes'));
        DB::table('staff_password_resets')->updateOrInsert(['user_id' => $target->id], [
            'token_hash' => hash('sha256', $token),
            'issued_by' => $issuer,
            'expires_at' => $expires,
            'created_at' => now(),
        ]);
        $target->version++;
        $target->save();

        $this->audit->record('staff.recovery_issued', $issuer, 'staff', $target->id, [
            'version' => $target->version,
        ], channel: $issuer === null ? 'console' : 'http');

        return ['user' => $target, 'token' => $token, 'expires_at' => $expires->toISOString()];
    }

    /** @param array<string, mixed> $data */
    public function resetPassword(array $data): void
    {
        $this->locked(function () use ($data): void {
            $user = User::query()->where('username', $data['username'])->lockForUpdate()->first();
            $reset = $user === null ? null : DB::table('staff_password_resets')
                ->where('user_id', $user->id)->lockForUpdate()->first();

            if ($user === null || ! $user->is_active || $reset === null
                || now()->greaterThanOrEqualTo($reset->expires_at)
                || ! hash_equals($reset->token_hash, hash('sha256', $data['token']))) {
                throw ValidationException::withMessages(['token' => ['The reset code is invalid or has expired.']]);
            }

            $user->password = $data['password'];
            $user->remember_token = Str::random(60);
            $user->version++;
            $user->save();
            DB::table('staff_password_resets')->where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            if ($user->email !== null) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            }
            $this->audit->record('staff.password_reset', null, 'staff', $user->id, [
                'version' => $user->version,
            ]);
        });
    }

    /**
     * @param list<string> $codes
     * @return list<int>
     */
    private function roles(User $actor, array $codes): array
    {
        $roles = Role::query()->whereIn('code', $codes)->with('permissions')->get();
        if ($roles->count() !== count($codes)) {
            throw ValidationException::withMessages(['role_codes' => ['A selected role no longer exists.']]);
        }
        $held = $actor->permissionCodes();
        foreach ($roles as $role) {
            $grants = $role->permissions->pluck('code')->intersect(array_keys(config('authorization.permissions')));
            abort_if($grants->diff($held)->isNotEmpty(), 403);
        }

        return $roles->pluck('id')->all();
    }

    private function manageable(User $actor, User $target): void
    {
        abort_if($target->assignedPermissionCodes()->diff($actor->permissionCodes())->isNotEmpty(), 403);
    }

    private function actor(User $actor, string $password): User
    {
        $fresh = User::query()->lockForUpdate()->findOrFail($actor->id);
        abort_unless($fresh->is_active, 403);
        if (! Hash::check($password, $fresh->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
        }

        return $fresh;
    }

    private function version(User $user, int $version): void
    {
        abort_if($user->version !== $version, 409);
    }

    private function locked(Closure $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            $lock = Permission::query()->where('code', 'staff.manage')->lockForUpdate()->first();
            abort_if($lock === null, 503);

            return $operation();
        }, 3);
    }
}
