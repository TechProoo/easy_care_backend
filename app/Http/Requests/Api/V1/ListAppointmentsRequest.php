<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAppointmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => trim($this->input('q'))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(array_keys(Appointment::TRANSITIONS))],
            'doctor_id' => ['sometimes', 'integer', 'min:1', 'max:999999999999999999'],
            'patient_id' => ['sometimes', 'integer', 'min:1', 'max:999999999999999999'],
            'from_date' => ['sometimes', 'date_format:Y-m-d'],
            'to_date' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('from_date'), 'after_or_equal:from_date')],
            'sort' => ['sometimes', 'string', Rule::in(['starts_at', '-starts_at', 'created_at', '-created_at'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')],
        ];
    }
}
