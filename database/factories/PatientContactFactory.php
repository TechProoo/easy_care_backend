<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientContact> */
class PatientContactFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'relationship' => 'next_of_kin',
            'phone' => '+2348000000000',
            'priority' => 1,
            'patient_id' => Patient::factory(),
        ];
    }
}
