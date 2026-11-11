<?php

namespace Database\Factories;

use App\Models\PatientCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientCategory> */
class PatientCategoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('category-????-####'),
            'name' => 'Synthetic patient category',
        ];
    }
}
