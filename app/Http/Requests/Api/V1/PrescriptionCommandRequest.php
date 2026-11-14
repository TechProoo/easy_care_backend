<?php

namespace App\Http\Requests\Api\V1;

use App\Services\ClinicalRecords;

class PrescriptionCommandRequest extends ClinicalCommandRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['kind']);
        if (in_array(ClinicalRecords::action($this), ['stop', 'complete'], true)) {
            $rules['current_password'] = ['required', 'string', 'max:1024'];
            $rules['reason'] = ['required', 'string', 'min:5', 'max:2000'];
        }

        return $rules;
    }
}
