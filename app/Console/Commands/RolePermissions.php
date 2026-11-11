<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('access:role {role} {permission?} {--revoke : Remove the specified permission}')]
#[Description('Inspect, grant or revoke a known permission for an existing staff role')]
class RolePermissions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AuditTrail $audit): int
    {
        $role = Role::query()->where('code', $this->argument('role'))->first();

        if ($role === null) {
            $audit->record($this->option('revoke') ? 'role.permission_revoked' : 'role.permission_granted',
                null, 'role', outcome: 'denied', channel: 'console');
            $this->error('Unknown role. Seed the reference catalog first.');

            return self::FAILURE;
        }

        $code = $this->argument('permission');

        if ($code === null) {
            if ($this->option('revoke')) {
                $audit->record('role.permission_revoked', null, 'role', $role->id, outcome: 'denied', channel: 'console');
                $this->error('Specify which permission to revoke.');

                return self::FAILURE;
            }

            $this->table(['Permission', 'Name'], $role->permissions()
                ->whereIn('code', array_keys(config('authorization.permissions')))
                ->orderBy('code')->get(['code', 'name'])
                ->map(fn (Permission $permission): array => [$permission->code, $permission->name])->all());

            return self::SUCCESS;
        }

        if (! array_key_exists($code, config('authorization.permissions'))) {
            $audit->record($this->option('revoke') ? 'role.permission_revoked' : 'role.permission_granted',
                null, 'role', $role->id, outcome: 'denied', channel: 'console');
            $this->error('Unknown permission. Use an exact code from the authorization catalog.');

            return self::FAILURE;
        }

        $permission = Permission::query()->where('code', $code)->first();

        if ($permission === null) {
            $audit->record($this->option('revoke') ? 'role.permission_revoked' : 'role.permission_granted',
                null, 'role', $role->id, outcome: 'denied', channel: 'console');
            $this->error('The permission catalog is not seeded.');

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');
        DB::transaction(function () use ($role, $permission, $revoke, $audit): void {
            Permission::query()->where('code', 'staff.manage')->lockForUpdate()->firstOrFail();
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            $hadPermission = $role->permissions()->whereKey($permission->id)->exists();
            if ($revoke) {
                $role->permissions()->detach($permission);
            } else {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            }
            $audit->record($revoke ? 'role.permission_revoked' : 'role.permission_granted', null, 'role', $role->id, [
                'permission_code' => $permission->code,
                'changed' => $revoke ? $hadPermission : ! $hadPermission,
            ], channel: 'console');
        }, 3);
        $this->info($revoke ? 'Permission revoked.' : 'Permission granted.');

        return self::SUCCESS;
    }
}
