<?php

namespace Database\Factories;

use App\Models\Prescription;
use App\Models\ClinicalRecord;
use App\Models\PrescriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Prescription> */
class PrescriptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'clinical_record_id' => ClinicalRecord::factory()->state(['kind' => 'prescription']),
            'instructions' => 'Synthetic test instructions',
        ];
    }

    public function withItems(int $count = 1): static
    {
        return $this->afterCreating(function (Prescription $prescription) use ($count): void {
            for ($line = 1; $line <= $count; $line++) {
                PrescriptionItem::factory()->for($prescription)->create(['line_number' => $line]);
            }
        });
    }
}
