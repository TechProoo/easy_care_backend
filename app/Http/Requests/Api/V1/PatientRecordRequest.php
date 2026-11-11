<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PatientRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['type', 'reason', 'mrn'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $field === 'mrn' ? strtoupper($value) : $value]);
            }
        }
    }

    public function rules(): array
    {
        $action = str_replace('api.v1.patients.records.', '', $this->route()->getName());
        $rules = ['version' => ['required', 'integer', 'min:1', 'max:4294967294'], 'reason' => ['required', 'string', 'min:5', 'max:2000']];
        if (! str_starts_with($action, 'flags.')) {
            $rules['current_password'] = ['required', 'string', 'max:1024'];
        }
        if ($action === 'flags.store') {
            $rules['type'] = ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/D'];
        }
        if ($action === 'cards.change') {
            $rules['category_id'] = ['required', 'integer', 'min:1', Rule::exists('patient_categories', 'id')];
            $patient = $this->attributes->get('patient_record');
            $unique = Rule::unique('patient_identifiers', 'identifier');
            if ($patient instanceof Patient && $patient->mrn === $this->input('mrn')) {
                $unique->ignore($patient->currentIdentifier()->sole()->id);
            }
            $rules['mrn'] = ['sometimes', 'required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9\/._-]*$/D', $unique];
        }
        if ($action === 'merges.request') {
            $rules['target_patient_id'] = ['required', 'integer', 'min:1', 'max:999999999999999999'];
            $rules['target_version'] = ['required', 'integer', 'min:1', 'max:4294967294'];
        }
        if (in_array($action, ['merges.approve', 'merges.reject', 'merges.cancel'], true)) {
            $rules['request_version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
        }];
    }
}
