<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientRelationship;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientRelationship> */
class PatientRelationshipFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'relationship' => 'parent',
            'patient_id' => Patient::factory(),
            'related_patient_id' => Patient::factory(),
        ];
    }
}
