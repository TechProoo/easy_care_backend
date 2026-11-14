<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClinicalRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListClinicalRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['kind' => ['sometimes', Rule::in(ClinicalRecord::ENTRY_KINDS)],
            'status' => ['sometimes', Rule::in(['draft', 'signed', 'voided'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')]];
    }
}
