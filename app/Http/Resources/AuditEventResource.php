<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'action' => $this->action,
            'outcome' => $this->outcome,
            'channel' => $this->channel,
            'actor_id' => $this->actor_id === null ? null : (int) $this->actor_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id === null ? null : (int) $this->subject_id,
            'request_id' => $this->request_id,
            'http_status' => $this->http_status === null ? null : (int) $this->http_status,
            'safe_summary' => json_decode($this->safe_summary, false, 512, JSON_THROW_ON_ERROR),
            'occurred_at' => CarbonImmutable::parse($this->occurred_at, 'UTC')->toISOString(),
        ];
    }
}
