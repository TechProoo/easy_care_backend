<?php

namespace App\Http\Requests\Api\V1;

use App\Services\EncounterFlow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EncounterCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'visit_type', 'consultation_type'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $action = EncounterFlow::action($this);
        $version = ['required', 'integer', 'min:1', 'max:4294967294'];
        $id = ['required', 'integer', 'min:1', 'max:999999999999999999'];
        $rules = $action === 'store' ? [] : ['version' => $version];
        if (in_array($action, ['store', 'arrive'], true)) {
            $rules += ['patient_id' => $id, 'patient_version' => $version, 'identity_verified' => ['required', 'accepted'],
                'care_setting' => ['required', Rule::in(['OPD', 'IPD', 'EMERGENCY'])]];
            if ($action === 'store') {
                $rules['visit_type'] = ['required', 'string', Rule::in(config('appointments.visit_types'))];
            }
        }
        if ($action !== 'store') {
            $rules['reason'] = ['required', 'string', 'min:5', 'max:2000'];
        }
        if (in_array($action, ['assign', 'reassign'], true)) {
            $rules += ['doctor_id' => $id, 'consultation_type' => ['required', 'string', Rule::in(config('appointments.visit_types'))]];
        }
        if (in_array($action, ['reassign', 'accept', 'complete'], true)) {
            $rules += ['assignment_id' => $id, 'assignment_version' => $version];
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
