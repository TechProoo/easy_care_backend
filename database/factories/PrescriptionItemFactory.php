<?php

namespace Database\Factories;

use App\Models\PrescriptionItem;
use App\Models\Prescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrescriptionItem> */
class PrescriptionItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(), 'line_number' => 1,
            'medication_name' => 'Synthetic test medication', 'form' => 'tablet', 'dose' => 'Test instruction only',
            'route' => 'oral', 'frequency' => 'Test instruction only', 'duration_value' => '3.000', 'duration_unit' => 'days',
        ];
    }
}
