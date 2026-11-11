<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use Closure;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Throwable;

class WriteTransactions
{
    /**
     * Only database-only operations may opt into automatic concurrency retries.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function run(Closure $operation, bool $databaseOnly = true): mixed
    {
        $outermost = DB::transactionLevel() === 0;
        try {
            return DB::transaction($operation, $databaseOnly ? 3 : 1);
        } catch (Throwable $exception) {
            if ($outermost && (new ConcurrencyErrorDetector)->causedByConcurrencyError($exception)) {
                throw new WriteConflict('write_busy', 'Another save is using this record. Retry the same submission shortly.', 503);
            }

            throw $exception;
        }
    }
}
