<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateStaffAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_creation_hashes_password_and_assigns_only_the_requested_role(): void
    {
        Role::factory()->create(['code' => 'doctor']);
        $this->artisan('staff:create clinician --role=doctor')
            ->expectsQuestion('First name', 'Test')
            ->expectsQuestion('Last name', 'Clinician')
            ->expectsQuestion('Email (optional)', 'clinician@example.test')
            ->expectsQuestion('Password (at least 12 characters)', 'Correct-secret-123!')
            ->expectsQuestion('Confirm password', 'Correct-secret-123!')
            ->assertSuccessful();
        $user = User::query()->sole();
        $this->assertTrue(Hash::check('Correct-secret-123!', $user->password));
        $this->assertSame('doctor', $user->roles()->sole()->code);
        $this->assertDatabaseCount('role_permissions', 0);
    }

    public function test_invalid_role_or_password_does_not_create_a_partial_account(): void
    {
        $this->artisan('staff:create clinician --role=missing')
            ->expectsQuestion('First name', 'Test')
            ->expectsQuestion('Last name', 'Clinician')
            ->expectsQuestion('Email (optional)', null)
            ->expectsQuestion('Password (at least 12 characters)', 'short')
            ->expectsQuestion('Confirm password', 'different')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_roles', 0);
    }
}
