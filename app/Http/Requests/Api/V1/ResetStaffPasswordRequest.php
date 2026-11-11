<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\StaffPasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class ResetStaffPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => mb_strtolower(trim($this->input('username')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:64'],
            'token' => ['required', 'string', 'size:48'],
            'password' => StaffPasswordRules::make(),
        ];
    }
}
