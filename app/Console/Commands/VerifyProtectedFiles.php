<?php

namespace App\Console\Commands;

use App\Services\ProtectedFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('files:verify')]
#[Description('Read-only verification of private file sizes, checksums and database references')]
class VerifyProtectedFiles extends Command
{
    public function handle(ProtectedFiles $files): int
    {
        try {
            $counts = $files->verify();
        } catch (Throwable) {
            $this->error('File verification could not complete. Check private storage and database availability.');

            return self::FAILURE;
        }
        $this->line('Checked: '.$counts['checked'].'; missing: '.$counts['missing'].'; corrupt: '.$counts['corrupt'].'; orphaned: '.$counts['orphaned'].'.');
        if ($counts['missing'] + $counts['corrupt'] + $counts['orphaned'] > 0) {
            $this->error('File verification found inconsistencies. No records or files were changed.');

            return self::FAILURE;
        }
        $this->info('File verification passed.');

        return self::SUCCESS;
    }
}
