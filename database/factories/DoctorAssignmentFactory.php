<?php

namespace Database\Factories;

use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorAssignment> */
class DoctorAssignmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'encounter_id' => Encounter::factory(), 'doctor_id' => User::factory(), 'assigned_by' => User::factory(),
            'assigned_at' => fn (array $attributes): string => Encounter::query()->findOrFail($attributes['encounter_id'])
                ->opened_at->addMinute()->format('Y-m-d H:i:s.u'),
            'consultation_type' => 'General Consultation',
        ];
    }
}
