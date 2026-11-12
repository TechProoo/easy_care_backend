<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Validator;

class UpdateAppointmentRequest extends StoreAppointmentRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['patient_id'], $rules['follow_up_of_id']);
        foreach (['first_name', 'last_name', 'phone', 'starts_at', 'ends_at', 'booking_channel', 'visit_type'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }
        $rules['version'] = ['required', 'integer', 'min:1', 'max:4294967294'];
        $rules['reason'] = ['required', 'string', 'min:5', 'max:2000'];

        return $rules;
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if (array_intersect(array_keys($this->all()), array_diff(array_keys($this->rules()), ['version', 'reason'])) === []) {
                $validator->errors()->add('changes', 'Supply at least one booking field to change.');
            }
        }];
    }
}
