<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(),
            'phone' => '+234'.fake()->numerify('##########'),
            'starts_at' => now()->addDay()->startOfHour(), 'ends_at' => null,
            'booking_channel' => 'Voice Call', 'visit_type' => 'General Consultation',
            'patient_id' => null, 'doctor_id' => null, 'booked_by' => User::factory(),
        ];
    }

    public function registered(Patient $patient): static
    {
        return $this->state(fn (): array => ['patient_id' => $patient->id,
            'first_name' => $patient->first_name, 'last_name' => $patient->last_name,
            'phone' => $patient->phone ?? '+2348000000000']);
    }
}
