<?php

namespace Database\Factories;

use App\Models\ClinicalProcedure;
use App\Models\ClinicalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClinicalProcedure> */
class ClinicalProcedureFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'clinical_record_id' => ClinicalRecord::factory()->state(['kind' => 'procedure']),
            'name' => 'Synthetic test procedure',
        ];
    }
}
