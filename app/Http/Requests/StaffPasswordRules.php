<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Validation\Rules\Password;

class StaffPasswordRules
{
    /** @return array<int, mixed> */
    public static function make(): array
    {
        return [
            'required', 'string', 'confirmed', 'max:72', Password::min(12)->mixedCase()->numbers(),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            },
        ];
    }
}
