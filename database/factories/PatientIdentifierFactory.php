<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PatientIdentifier> */
class PatientIdentifierFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'identifier' => 'OLD-'.strtoupper((string) Str::uuid()),
            'kind' => 'legacy_mrn',
            'retired_at' => now(),
            'patient_id' => Patient::factory(),
        ];
    }
}
