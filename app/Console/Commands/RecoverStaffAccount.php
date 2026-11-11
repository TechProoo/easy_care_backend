<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StaffAdministration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('staff:recover {username : Exact username of an active staff member}')]
#[Description('Issue a one-use recovery code from the trusted hospital server console')]
class RecoverStaffAccount extends Command
{
    public function handle(StaffAdministration $staff): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively after verifying the staff member identity.');

            return self::FAILURE;
        }

        $target = User::query()->where('username', mb_strtolower(trim($this->argument('username'))))->first();
        if ($target === null || ! $target->is_active) {
            $this->error('An active staff account with that username was not found.');

            return self::FAILURE;
        }

        if (! $this->confirm('Have you verified this staff member identity using the hospital recovery procedure?')) {
            $this->info('Recovery cancelled.');

            return self::FAILURE;
        }

        $reset = $staff->issueConsoleReset($target);
        $this->warn('Give this code privately to the verified staff member. It is shown once.');
        $this->line($reset['token']);
        $this->info('Expires at '.$reset['expires_at'].'. Open /forgot-password to choose a new password.');

        return self::SUCCESS;
    }
}
