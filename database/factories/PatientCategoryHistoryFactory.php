<?php

namespace Database\Factories;

use App\Models\PatientCategoryHistory;
use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientCategoryHistory> */
class PatientCategoryHistoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(), 'previous_category_id' => PatientCategory::factory(),
            'new_category_id' => PatientCategory::factory(), 'previous_mrn' => 'SYNTHETIC-OLD', 'new_mrn' => 'SYNTHETIC-NEW',
            'reason' => 'Synthetic category change reason', 'changed_by' => User::factory(), 'changed_at' => now('UTC'),
        ];
    }
}
