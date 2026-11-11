<?php

namespace Database\Factories;

use App\Models\PatientAccessEvent;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientAccessEvent> */
class PatientAccessEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(), 'action' => 'restrict',
            'reason' => 'Synthetic access review reason', 'actor_id' => User::factory(), 'occurred_at' => now('UTC'),
        ];
    }
}
