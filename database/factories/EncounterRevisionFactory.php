<?php

namespace Database\Factories;

use App\Models\Encounter;
use App\Services\EncounterFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\EncounterRevision> */
class EncounterRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['encounter_id' => Encounter::factory(), 'version' => 1, 'operation' => 'opened',
            'actor_id' => fn (array $attributes): int => Encounter::findOrFail($attributes['encounter_id'])->opened_by,
            'reason' => null, 'snapshot' => fn (array $attributes): array => EncounterFlow::snapshot(Encounter::findOrFail($attributes['encounter_id'])),
            'recorded_at' => now('UTC')];
    }
}
