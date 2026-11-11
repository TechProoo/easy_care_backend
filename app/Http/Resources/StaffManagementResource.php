<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class StaffManagementResource extends StaffResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'version' => $this->version,
            'last_login_at' => $this->last_login_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
