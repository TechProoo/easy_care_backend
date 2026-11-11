<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email_or_username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function authenticate(): User
    {
        $identity = $this->validated('email_or_username');
        $key = 'staff-login:'.hash('sha256', Str::lower($identity).'|'.$this->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }

        $field = filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $guard = Auth::guard('web');

        if (! $guard->attempt([$field => $identity, 'password' => $this->validated('password'), 'is_active' => true])) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email_or_username' => ['The username/email or password is incorrect.'],
            ]);
        }

        RateLimiter::clear($key);

        return $guard->user();
    }
}
