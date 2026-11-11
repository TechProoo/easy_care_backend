<?php

namespace App\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProtectedFileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'byte_size' => (int) $this->byte_size,
            'scan_status' => $this->scan_status,
            'uploaded_by' => (int) $this->uploaded_by,
            'created_at' => CarbonImmutable::parse($this->created_at, 'UTC')->toISOString(),
        ];
    }
}
