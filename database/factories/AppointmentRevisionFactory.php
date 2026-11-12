<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentRevision;
use App\Services\AppointmentBookings;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppointmentRevision> */
class AppointmentRevisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'version' => fn (array $attributes): int => Appointment::query()->findOrFail($attributes['appointment_id'])->version,
            'actor_id' => fn (array $attributes): int => Appointment::query()->findOrFail($attributes['appointment_id'])->booked_by,
            'operation' => 'created', 'reason' => null,
            'snapshot' => fn (array $attributes): array => AppointmentBookings::snapshot(Appointment::query()->findOrFail($attributes['appointment_id'])),
            'recorded_at' => now('UTC'),
        ];
    }
}
