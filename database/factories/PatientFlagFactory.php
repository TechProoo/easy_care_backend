<?php

namespace Database\Factories;

use App\Models\PatientFlag;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientFlag> */
class PatientFlagFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(), 'type' => 'identity-review',
            'reason' => 'Synthetic identity review reason', 'created_by' => User::factory(),
        ];
    }
}
