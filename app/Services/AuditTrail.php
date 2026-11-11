<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuditTrail
{
    public const ACTIONS = [
        'staff.created', 'staff.updated', 'staff.recovery_issued', 'staff.password_reset',
        'role.permission_granted', 'role.permission_revoked',
        'auth.login', 'auth.logout', 'audit.viewed', 'command.replayed',
        'file.uploaded', 'file.listed', 'file.viewed', 'file.download_authorized', 'file.integrity_checked',
    ];

    /** @param array<string, mixed> $summary */
    public function record(
        string $action,
        ?int $actorId,
        string $subjectType,
        ?int $subjectId = null,
        array $summary = [],
        string $outcome = 'succeeded',
        string $channel = 'http',
        ?int $httpStatus = null,
    ): void {
        if (! in_array($action, self::ACTIONS, true)
            || ! in_array($outcome, ['succeeded', 'denied', 'failed'], true)
            || ! in_array($channel, ['http', 'console'], true)
            || ! in_array($subjectType, ['staff', 'role', 'audit', 'file'], true)) {
            throw new InvalidArgumentException('Unknown audit event classification.');
        }

        $this->validateSummary($action, $summary);
        $requestId = (string) Str::uuid();
        if ($channel === 'http' && app()->bound('request')) {
            $request = request();
            $requestId = $request->attributes->get('audit_request_id', $requestId);
            $request->attributes->set('audit_request_id', $requestId);
        }

        DB::table('audit_events')->insert([
            'action' => $action,
            'actor_id' => $actorId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'outcome' => $outcome,
            'channel' => $channel,
            'request_id' => $requestId,
            'http_status' => $httpStatus,
            'safe_summary' => json_encode((object) $summary, JSON_THROW_ON_ERROR),
            'occurred_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    /** @param array<string, mixed> $summary */
    private function validateSummary(string $action, array $summary): void
    {
        $allowed = match ($action) {
            'staff.created' => ['role_codes', 'department_id', 'is_active', 'version'],
            'staff.updated' => ['changed_fields', 'previous_role_codes', 'role_codes', 'previous_department_id',
                'department_id', 'previous_is_active', 'is_active', 'version', 'sessions_revoked'],
            'staff.recovery_issued', 'staff.password_reset' => ['version'],
            'role.permission_granted', 'role.permission_revoked' => ['permission_code', 'changed'],
            'file.uploaded' => ['byte_size', 'mime_type'],
            'file.integrity_checked' => ['checked', 'missing', 'corrupt', 'orphaned'],
            default => [],
        };

        foreach ($summary as $key => $value) {
            $valid = in_array($key, $allowed, true) && match ($key) {
                'department_id', 'previous_department_id' => $value === null || (is_int($value) && $value > 0),
                'byte_size', 'checked', 'missing', 'corrupt', 'orphaned' => is_int($value) && $value >= 0,
                'mime_type' => in_array($value, ['application/pdf', 'image/jpeg', 'image/png'], true),
                'version' => is_int($value) && $value > 0,
                'is_active', 'previous_is_active', 'sessions_revoked', 'changed' => is_bool($value),
                'permission_code' => is_string($value) && array_key_exists($value, config('authorization.permissions')),
                'role_codes', 'previous_role_codes' => is_array($value) && array_is_list($value)
                    && count($value) <= 100 && collect($value)->every(fn ($code): bool => is_string($code)
                        && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $code) === 1),
                'changed_fields' => is_array($value) && array_is_list($value)
                    && collect($value)->every(fn ($field): bool => is_string($field) && in_array($field,
                        ['first_name', 'last_name', 'middle_name', 'email', 'staff_code', 'department_id', 'is_active', 'role_codes'], true)),
                default => false,
            };
            if (! $valid) {
                throw new InvalidArgumentException('Unsafe audit summary field.');
            }
        }
    }
}
