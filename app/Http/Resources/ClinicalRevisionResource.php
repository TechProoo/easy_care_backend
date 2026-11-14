<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClinicalRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = $this->resource->only(['id', 'clinical_record_id', 'version', 'operation', 'actor_id', 'reason', 'snapshot']);
        if (($result['snapshot']['kind'] ?? null) === 'entry' && isset($result['snapshot']['payload']['fields'])) {
            $result['snapshot']['payload']['fields'] = (object) $result['snapshot']['payload']['fields'];
        }

        return $result
            + ['actor_name' => $this->actor?->name, 'recorded_at' => $this->recorded_at->toISOString()];
    }
}
