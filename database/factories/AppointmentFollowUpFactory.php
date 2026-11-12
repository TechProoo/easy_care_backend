<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentFollowUp;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppointmentFollowUp> */
class AppointmentFollowUpFactory extends Factory
{
    public function definition(): array
    {
        return [
            'previous_appointment_id' => function (): int {
                $previous = Appointment::factory()->registered(Patient::factory()->create())->create();
                $previous->forceFill(['status' => 'attended', 'version' => 2])->save();

                return $previous->id;
            },
            'appointment_id' => function (array $attributes): int {
                $previous = Appointment::query()->findOrFail($attributes['previous_appointment_id']);

                return Appointment::factory()->registered($previous->patient)->create(['starts_at' => $previous->starts_at->addDay()])->id;
            },
            'created_by' => fn (array $attributes): int => Appointment::query()->findOrFail($attributes['appointment_id'])->booked_by,
            'created_at' => now('UTC'),
        ];
    }
}
