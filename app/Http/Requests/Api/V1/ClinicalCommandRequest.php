<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClinicalRecord;
use App\Services\ClinicalRecords;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ClinicalCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        $action = ClinicalRecords::action($this);
        $version = ['required', 'integer', 'min:1', 'max:4294967294'];
        $rules = ['encounter_version' => $version];
        if ($action !== 'store') {
            $rules['version'] = $version;
        }
        if ($action === 'store') {
            $rules['kind'] = ['required', Rule::in(ClinicalRecord::ENTRY_KINDS)];
        }
        if (in_array($action, ['store', 'update', 'amend'], true)) {
            $rules['payload'] = ['required', 'array', 'min:1', 'max:20'];
        }
        if (in_array($action, ['sign', 'amend', 'void'], true)) {
            $rules['current_password'] = ['required', 'string', 'max:1024'];
        }
        if (in_array($action, ['amend', 'void'], true)) {
            $rules['reason'] = ['required', 'string', 'min:5', 'max:2000'];
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
