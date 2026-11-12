<?php

namespace App\Http\Resources;

use App\Services\AppointmentBookings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return AppointmentBookings::snapshot($this->resource);
    }
}
