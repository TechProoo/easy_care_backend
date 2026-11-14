<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClinicalRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPatientHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['kind' => ['sometimes', Rule::in([...ClinicalRecord::ENTRY_KINDS, 'prescription'])],
            'status' => ['sometimes', Rule::in(['signed', 'voided'])],
            'encounter_id' => ['sometimes', 'integer', 'min:1', 'max:999999999999999999'],
            'include_superseded' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')]];
    }
}
