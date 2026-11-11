<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['username', 'email', 'staff_code', 'first_name', 'last_name', 'middle_name'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => in_array($field, ['username', 'email'], true) ? mb_strtolower($value) : $value]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users')],
            'first_name' => ['required', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:64'],
            'middle_name' => ['nullable', 'string', 'max:64'],
            'email' => ['present', 'nullable', 'email', 'max:255', Rule::unique('users')->ignore($target)],
            'staff_code' => ['present', 'nullable', 'string', 'max:64', Rule::unique('users')->ignore($target)],
            'department_id' => ['present', 'nullable', 'integer', Rule::exists('departments', 'id')],
            'role_codes' => ['present', 'array', 'max:20'],
            'role_codes.*' => ['required', 'string', 'distinct:strict', Rule::exists('roles', 'code')],
            'is_active' => ['required', 'boolean'],
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => ['prohibited'],
            'permissions' => ['prohibited'],
            'version' => ['prohibited'],
        ];
    }
}
