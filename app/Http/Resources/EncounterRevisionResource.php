<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EncounterRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'encounter_id', 'version', 'operation', 'actor_id', 'reason', 'snapshot']) + [
            'recorded_at' => $this->recorded_at->toISOString(),
        ];
    }
}
