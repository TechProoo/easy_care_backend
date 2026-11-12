<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

class AppointmentTime
{
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-](?:0[0-9]|1[0-3]):[0-5][0-9]|[+-]14:00)$/D', $value) !== 1) {
            return null;
        }
        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        $instant = CarbonImmutable::instance($date)->utc();

        return $instant->year >= 1000 && $instant->year <= 9999 ? $instant : null;
    }
}
