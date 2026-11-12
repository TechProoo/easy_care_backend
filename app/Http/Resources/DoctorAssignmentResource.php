<?php

namespace App\Http\Resources;

use App\Services\EncounterFlow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return EncounterFlow::assignment($this->resource) + ['end_reason' => $this->end_reason, 'doctor_name' => $this->doctor?->name];
    }
}
