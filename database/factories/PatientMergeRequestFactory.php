<?php

namespace Database\Factories;

use App\Models\PatientMergeRequest;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientMergeRequest> */
class PatientMergeRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'source_patient_id' => Patient::factory(), 'target_patient_id' => Patient::factory(),
            'source_version' => 1, 'target_version' => 1, 'requested_by' => User::factory(),
            'reason' => 'Synthetic identity review reason', 'status' => 'pending', 'version' => 1,
        ];
    }
}
