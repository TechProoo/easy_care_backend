<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AuthorizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (config('authorization.permissions') as $code => $name) {
                Permission::query()->firstOrCreate(['code' => $code], ['name' => $name]);
            }

            $baselines = [
                config('authorization.baseline') => config('authorization.role_defaults'),
                config('authorization.management_baseline') => config('authorization.management_role_defaults'),
                config('authorization.audit_baseline') => config('authorization.audit_role_defaults'),
            ];

            foreach ($baselines as $baseline => $roles) {
                $firstApplication = DB::table('permission_baselines')->insertOrIgnore([
                    'code' => $baseline,
                    'applied_at' => now(),
                ]);

                if ($firstApplication === 0) {
                    continue;
                }

                foreach ($roles as $code => $permissions) {
                    $role = Role::query()->where('code', $code)->firstOrFail();
                    $role->permissions()->syncWithoutDetaching(
                        Permission::query()->whereIn('code', $permissions)->pluck('id')->all(),
                    );
                }
            }
        });
    }
}
