<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Encounter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListEncountersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['sometimes', Rule::in(array_keys(Encounter::TRANSITIONS))],
            'queue' => ['sometimes', Rule::in(['unassigned', 'waiting', 'in_progress'])],
            'doctor_id' => ['sometimes', 'integer', 'min:1', 'max:999999999999999999'],
            'patient_id' => ['sometimes', 'integer', 'min:1', 'max:999999999999999999'],
            'care_setting' => ['sometimes', Rule::in(['OPD', 'IPD', 'EMERGENCY'])],
            'from_date' => ['sometimes', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('from_date'), 'after_or_equal:from_date')],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')]];
    }
}
