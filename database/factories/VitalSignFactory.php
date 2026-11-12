<?php

namespace Database\Factories;

use App\Models\VitalSign;
use App\Models\ClinicalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VitalSign> */
class VitalSignFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'clinical_record_id' => ClinicalRecord::factory()->state(['kind' => 'vitals']),
            'observed_at' => now('UTC'), 'temperature_c' => '36.50', 'systolic_mmhg' => 120, 'diastolic_mmhg' => 80,
            'weight_kg' => '70.000', 'height_m' => '1.700',
        ];
    }
}
