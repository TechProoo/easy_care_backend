<?php

namespace App\Console\Commands;

use App\Http\Requests\StaffPasswordRules;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[Signature('staff:create {username?} {--role= : Existing role code; omit for no role}')]
#[Description('Create a staff account using private interactive password prompts')]
class CreateStaffAccount extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AuditTrail $audit): int
    {
        if (! $this->input->isInteractive()) {
            $audit->record('staff.created', null, 'staff', outcome: 'denied', channel: 'console');
            $this->error('Run this command interactively so the password can be entered privately.');

            return self::FAILURE;
        }

        $username = Str::lower(trim((string) ($this->argument('username') ?? $this->ask('Username'))));
        $firstName = $this->ask('First name');
        $lastName = $this->ask('Last name');
        $email = $this->ask('Email (optional)');
        $password = $this->secret('Password (at least 12 characters)');
        $confirmation = $this->secret('Confirm password');
        $role = $this->option('role');
        $attributes = [
            'username' => $username,
            'first_name' => is_string($firstName) ? trim($firstName) : $firstName,
            'last_name' => is_string($lastName) ? trim($lastName) : $lastName,
            'email' => $email ? Str::lower(trim($email)) : null,
            'password' => $password,
            'password_confirmation' => $confirmation,
            'role' => $role,
        ];
        $validator = Validator::make($attributes, [
            'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/', Rule::unique('users')],
            'first_name' => ['required', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')],
            'password' => StaffPasswordRules::make(),
            'role' => ['nullable', 'string', Rule::exists('roles', 'code')],
        ]);

        if ($validator->fails()) {
            $audit->record('staff.created', null, 'staff', outcome: 'denied', channel: 'console');
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($attributes, $role, $audit): void {
            $user = User::query()->create(collect($attributes)->only([
                'username', 'first_name', 'last_name', 'email', 'password',
            ])->all());

            if ($role) {
                $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());
            }
            $user->refresh();
            $audit->record('staff.created', null, 'staff', $user->id, [
                'role_codes' => $user->roles()->orderBy('code')->pluck('code')->all(),
                'is_active' => $user->is_active,
                'version' => $user->version,
            ], channel: 'console');
        });

        $this->info('Staff account created. No additional permissions were granted.');

        return self::SUCCESS;
    }
}
