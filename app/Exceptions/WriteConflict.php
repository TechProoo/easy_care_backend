<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class WriteConflict extends HttpException
{
    public function __construct(public string $errorCode, string $message, int $status = 409)
    {
        parent::__construct($status, $message, headers: $status === 503 ? ['Retry-After' => '1'] : []);
    }
}
