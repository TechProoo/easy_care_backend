<?php

namespace Database\Factories;

use App\Models\ClinicalTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClinicalTemplate> */
class ClinicalTemplateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'test-'.fake()->unique()->uuid(), 'name' => 'Synthetic clinical template',
            'created_by' => User::factory(),
        ];
    }
}
