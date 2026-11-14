<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class PatientHistoryResource extends ClinicalResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['encounter' => ['id' => $this->encounter->id,
            'status' => $this->encounter->status, 'care_setting' => $this->encounter->care_setting,
            'opened_at' => $this->encounter->opened_at->toISOString(), 'closed_at' => $this->encounter->closed_at?->toISOString()]];
    }
}
