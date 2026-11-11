<?php

namespace Database\Seeders;

use App\Models\PatientCategory;
use Illuminate\Database\Seeder;

class PatientCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'private' => 'Private',
            'company' => 'Company',
            'hmo' => 'HMO',
            'nhis' => 'NHIS',
            'family' => 'Family',
            'staff' => 'Staff',
            'walk-in' => 'Walk-in',
        ] as $code => $name) {
            PatientCategory::query()->firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
