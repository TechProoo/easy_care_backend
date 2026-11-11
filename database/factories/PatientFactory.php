<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Patient> */
class PatientFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'mrn' => 'TEST-'.strtoupper((string) Str::uuid()),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-85 years', '-1 year')->format('Y-m-d'),
            'dob_estimated' => false,
            'category_id' => PatientCategory::factory(),
            'created_by' => User::factory(),
        ];
    }
}
