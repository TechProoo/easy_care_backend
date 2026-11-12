<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/** @extends Factory<Encounter> */
class EncounterFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(), 'appointment_id' => null,
            'category_id' => fn (array $attributes): int => Patient::query()->findOrFail($attributes['patient_id'])->category_id,
            'opened_by' => User::factory(), 'visit_type' => 'General Consultation',
            'care_setting' => 'OPD', 'opened_at' => now()->startOfSecond(),
        ];
    }

    public function fromAppointment(Appointment $appointment): static
    {
        if ($appointment->patient_id === null) {
            throw new InvalidArgumentException('Link a registered patient before creating its visit.');
        }

        return $this->state(fn (): array => ['patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id, 'visit_type' => $appointment->visit_type]);
    }
}
