<?php

namespace App\Http\Resources;

use App\Services\EncounterFlow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EncounterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignment = $this->currentAssignment;

        return $this->resource->only(['id', 'patient_id', 'appointment_id', 'category_id', 'opened_by', 'visit_type', 'care_setting', 'status', 'version']) + [
            'opened_at' => $this->opened_at->toISOString(), 'closed_at' => $this->closed_at?->toISOString(),
            'queue' => in_array($this->status, ['closed', 'cancelled'], true) ? null
                : ($assignment === null ? 'unassigned' : ($assignment->status === 'accepted' ? 'in_progress' : 'waiting')),
            'patient' => $this->patient->only(['id', 'mrn', 'first_name', 'last_name', 'version']),
            'current_assignment' => $assignment === null ? null : EncounterFlow::assignment($assignment) + ['doctor_name' => $assignment->doctor?->name],
        ];
    }
}
