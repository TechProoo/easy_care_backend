<?php

namespace Database\Factories;

use App\Models\PatientMerge;
use App\Models\PatientMergeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientMerge> */
class PatientMergeFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (PatientMerge $merge): void {
            $merge->source->forceFill(['access_status' => 'merged', 'version' => $merge->source_version])->save();
            $merge->target->forceFill(['version' => $merge->target_version])->save();
        });
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $request = PatientMergeRequest::factory()->create();
        $reviewer = User::factory()->create();
        $request->forceFill(['status' => 'approved', 'version' => 2, 'reviewed_by' => $reviewer->id,
            'review_reason' => 'Synthetic independent review', 'reviewed_at' => now('UTC')])->save();

        return ['request_id' => $request->id, 'source_patient_id' => $request->source_patient_id,
            'target_patient_id' => $request->target_patient_id, 'source_version' => 2, 'target_version' => 2,
            'approved_by' => $reviewer->id, 'reason' => $request->reason, 'approved_at' => now('UTC')];
    }
}
