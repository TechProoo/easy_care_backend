<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\Role;
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
    public function handle(): int
    {
        $role = Role::query()->where('code', $this->argument('role'))->first();

        if ($role === null) {
            $this->error('Unknown role. Seed the reference catalog first.');

            return self::FAILURE;
        }

        $code = $this->argument('permission');

        if ($code === null) {
            if ($this->option('revoke')) {
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
            $this->error('Unknown permission. Use an exact code from the authorization catalog.');

            return self::FAILURE;
        }

        $permission = Permission::query()->where('code', $code)->first();

        if ($permission === null) {
            $this->error('The permission catalog is not seeded.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $role->permissions()->detach($permission);
            $this->info('Permission revoked.');
        } else {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $this->info('Permission granted.');
        }

        return self::SUCCESS;
    }
}
