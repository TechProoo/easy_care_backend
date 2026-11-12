<?php

namespace Database\Factories;

use App\Models\Diagnosis;
use App\Models\ClinicalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Diagnosis> */
class DiagnosisFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'clinical_record_id' => ClinicalRecord::factory()->state(['kind' => 'diagnosis']),
            'description' => 'Synthetic test diagnosis',
        ];
    }
}
