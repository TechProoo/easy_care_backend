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

            $firstApplication = DB::table('permission_baselines')->insertOrIgnore([
                'code' => config('authorization.baseline'),
                'applied_at' => now(),
            ]);

            if ($firstApplication === 0) {
                return;
            }

            foreach (config('authorization.role_defaults') as $code => $permissions) {
                $role = Role::query()->where('code', $code)->firstOrFail();
                $role->permissions()->syncWithoutDetaching(
                    Permission::query()->whereIn('code', $permissions)->pluck('id')->all(),
                );
            }
        });
    }
}
