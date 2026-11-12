<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Appointment;
use App\Services\AppointmentTime;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['first_name', 'last_name', 'phone', 'booking_channel', 'visit_type', 'specialist_name', 'specialist_email', 'reason'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                if ($field === 'phone') {
                    $value = str_replace([' ', '-', '(', ')'], '', $value);
                    if ($value !== '' && ! str_starts_with($value, '+')) {
                        $value = '+'.$value;
                    }
                }
                if ($field === 'specialist_email') {
                    $value = mb_strtolower($value);
                }
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:64'], 'last_name' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/D'],
            'starts_at' => ['required', 'string', $this->instantRule()],
            'ends_at' => ['required', 'string', $this->instantRule()],
            'booking_channel' => ['required', 'string', Rule::in(config('appointments.booking_channels'))],
            'visit_type' => ['required', 'string', Rule::in(config('appointments.visit_types'))],
            'specialist_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'specialist_email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'patient_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999999999999999'],
            'doctor_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999999999999999'],
            'follow_up_of_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999999999999999999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
            $record = $this->attributes->get('appointment_record');
            $start = $this->exists('starts_at') ? AppointmentTime::parse($this->input('starts_at')) : ($record instanceof Appointment ? $record->starts_at : null);
            $end = $this->exists('ends_at') ? AppointmentTime::parse($this->input('ends_at')) : ($record instanceof Appointment ? $record->ends_at : null);
            if ($start !== null && $end !== null && $end->lessThanOrEqualTo($start)) {
                $validator->errors()->add('ends_at', 'The end must be after the start.');
            }
            if (($this->isMethod('POST') || $this->exists('starts_at')) && $start !== null && $start->lessThan(now('UTC'))) {
                $validator->errors()->add('starts_at', 'Choose a start time that has not passed.');
            }
        }];
    }

    protected function instantRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (AppointmentTime::parse($value) === null) {
                $fail('Supply a real ISO 8601 timestamp with seconds and an explicit UTC offset or Z.');
            }
        };
    }
}
