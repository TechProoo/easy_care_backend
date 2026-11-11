<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferenceDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalog = [
                'admin' => 'Administration',
                'frontdesk' => 'Frontdesk',
                'doctor' => 'Medical',
                'nurse' => 'Nursing',
                'pharmacy' => 'Pharmacy',
                'hmo' => 'HMO',
                'diagnostics-and-radiologist' => 'Diagnostics and Radiology',
            ];

            foreach ($catalog as $code => $name) {
                Department::query()->firstOrCreate(['code' => $code], ['name' => $name]);
                Role::query()->firstOrCreate(['code' => $code], ['name' => $name]);
            }

            $this->call(AuthorizationSeeder::class);
        });
    }
}
