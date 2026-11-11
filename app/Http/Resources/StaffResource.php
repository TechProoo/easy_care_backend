<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'staff_code' => $this->staff_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'department' => $this->department?->only(['id', 'code', 'name']),
            'roles' => $this->roles->sortBy('code')->values()->map->only(['code', 'name']),
            'permissions' => $this->resource->permissionCodes(),
            'created_at' => $this->created_at?->toISOString(),
            'last_login_at' => $this->last_login_at?->toISOString(),
        ];
    }
}
