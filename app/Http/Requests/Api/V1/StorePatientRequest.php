<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['mrn', 'first_name', 'last_name', 'middle_name', 'sex', 'email', 'phone',
            'marital_status', 'religion', 'occupation', 'state_of_origin', 'address'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $value = trim($value);
                $value = match ($field) {
                    'mrn' => strtoupper($value),
                    'email' => mb_strtolower($value),
                    'phone' => $this->phone($value),
                    default => $value,
                };
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
        if (is_array($this->input('contacts'))) {
            $this->merge(['contacts' => array_map(function (mixed $contact): mixed {
                if (! is_array($contact)) {
                    return $contact;
                }
                foreach (['name', 'relationship', 'phone'] as $field) {
                    if (isset($contact[$field]) && is_string($contact[$field])) {
                        $contact[$field] = $field === 'phone' ? $this->phone($contact[$field]) : trim($contact[$field]);
                    }
                }

                return $contact;
            }, $this->input('contacts'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mrn' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9\/._-]*$/D', Rule::unique('patient_identifiers', 'identifier')],
            'first_name' => ['required', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:64'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sex' => ['sometimes', 'nullable', 'string', 'max:64'],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'dob_estimated' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/D'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'max:64'],
            'religion' => ['sometimes', 'nullable', 'string', 'max:64'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:191'],
            'state_of_origin' => ['sometimes', 'nullable', 'string', 'max:191'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category_id' => ['required', 'integer', 'min:1', Rule::exists('patient_categories', 'id')],
            'contacts' => ['sometimes', 'array', 'list', 'max:10'],
            'contacts.*' => ['required', 'array:name,relationship,phone'],
            'contacts.*.name' => ['required', 'string', 'max:191'],
            'contacts.*.relationship' => ['required', 'string', 'max:64'],
            'contacts.*.phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/D'],
            'acknowledged_duplicate_ids' => ['sometimes', 'array', 'list', 'max:20'],
            'acknowledged_duplicate_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
            $patient = $this->attributes->get('patient_record');
            $estimated = $this->input('dob_estimated', $patient instanceof Patient ? $patient->dob_estimated : false);
            $birthDate = $this->input('date_of_birth', $patient instanceof Patient ? $patient->date_of_birth?->format('Y-m-d') : null);
            if ($estimated && $birthDate === null) {
                $validator->errors()->add('date_of_birth', 'An estimated birth date requires a date.');
            }
        }];
    }

    private function phone(string $value): string
    {
        $value = str_replace([' ', '-', '(', ')'], '', trim($value));

        return $value === '' || str_starts_with($value, '+') ? $value : '+'.$value;
    }
}
