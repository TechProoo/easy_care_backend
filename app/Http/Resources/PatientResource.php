<?php

namespace App\Http\Resources;

use App\Models\PatientContact;
use App\Models\PatientIdentifier;
use Illuminate\Http\Request;

class PatientResource extends PatientSummaryResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'email' => $this->email,
            'phone' => $this->phone,
            'marital_status' => $this->marital_status,
            'religion' => $this->religion,
            'occupation' => $this->occupation,
            'state_of_origin' => $this->state_of_origin,
            'address' => $this->address,
            'life_status' => $this->life_status,
            'deceased_at' => $this->deceased_at?->toISOString(),
            'created_by' => $this->created_by,
            'contacts' => $this->contacts->map(fn (PatientContact $contact): array => $contact->only(['id', 'name', 'relationship', 'phone', 'priority'])),
            'identifiers' => $this->identifiers->map(fn (PatientIdentifier $identifier): array => [
                'identifier' => $identifier->identifier, 'kind' => $identifier->kind,
                'retired_at' => $identifier->retired_at?->toISOString(),
            ]),
        ];
    }
}
