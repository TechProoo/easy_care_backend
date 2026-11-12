<?php

namespace Database\Factories;

use App\Models\ClinicalRecord;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClinicalRecord> */
class ClinicalRecordFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'encounter_id' => Encounter::factory(),
            'patient_id' => fn (array $attributes): int => Encounter::query()->findOrFail($attributes['encounter_id'])->patient_id,
            'kind' => 'entry', 'authored_by' => User::factory(),
            'recorded_at' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])->opened_at->format('Y-m-d H:i:s.u'),
        ];
    }

    public function forEncounter(Encounter $encounter): static
    {
        return $this->state(fn (): array => ['encounter_id' => $encounter->id, 'patient_id' => $encounter->patient_id]);
    }

    public function amending(ClinicalRecord $record): static
    {
        return $this->state(fn (): array => ['encounter_id' => $record->encounter_id, 'patient_id' => $record->patient_id,
            'kind' => $record->kind, 'amends_id' => $record->id, 'amendment_reason' => 'Correcting a synthetic test record',
            'recorded_at' => $record->finalized_at]);
    }
}
