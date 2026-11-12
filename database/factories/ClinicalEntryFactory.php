<?php

namespace Database\Factories;

use App\Models\ClinicalEntry;
use App\Models\ClinicalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClinicalEntry> */
class ClinicalEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'clinical_record_id' => ClinicalRecord::factory()->state(['kind' => 'entry']),
            'section' => 'note', 'fields' => ['summary' => 'Synthetic test note'], 'content' => null,
        ];
    }
}
