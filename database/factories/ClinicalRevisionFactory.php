<?php

namespace Database\Factories;

use App\Models\ClinicalRecord;
use App\Models\ClinicalRevision;
use App\Services\ClinicalRecords;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClinicalRevision> */
class ClinicalRevisionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['clinical_record_id' => ClinicalRecord::factory(), 'version' => 1, 'operation' => 'created',
            'actor_id' => fn (array $attributes): int => ClinicalRecord::findOrFail($attributes['clinical_record_id'])->authored_by,
            'reason' => null,
            'snapshot' => fn (array $attributes): array => ClinicalRecords::snapshot(ClinicalRecord::findOrFail($attributes['clinical_record_id'])),
            'recorded_at' => now('UTC')];
    }
}
