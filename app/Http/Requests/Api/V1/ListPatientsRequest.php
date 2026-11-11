<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPatientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['q', 'mrn'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $field === 'mrn' ? strtoupper($value) : $value]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mrn' => ['sometimes', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9\/._-]*$/D'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'date_of_birth' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sort' => ['sometimes', 'string', Rule::in(['mrn', '-mrn', 'last_name', '-last_name', 'created_at', '-created_at'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')],
        ];
    }
}
