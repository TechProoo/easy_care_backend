<?php

namespace App\Http\Requests\Api\V1;

class CheckPatientDuplicatesRequest extends StorePatientRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:64'],
            'date_of_birth' => ['present', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/D'],
            'exclude_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
