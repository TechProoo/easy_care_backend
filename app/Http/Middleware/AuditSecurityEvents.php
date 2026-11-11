<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditTrail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditSecurityEvents
{
    public function __construct(private AuditTrail $audit) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();
        $action = match ($request->route()?->getName()) {
            'api.v1.auth.login' => 'auth.login',
            'api.v1.auth.logout' => 'auth.logout',
            'api.v1.auth.password.reset' => 'staff.password_reset',
            'api.v1.staff-management.store' => 'staff.created',
            'api.v1.staff-management.update' => 'staff.updated',
            'api.v1.staff-management.password-reset' => 'staff.recovery_issued',
            'api.v1.audit-events.index' => 'audit.viewed',
            'api.v1.files.store' => 'file.uploaded',
            'api.v1.files.index' => 'file.listed',
            'api.v1.files.show' => 'file.viewed',
            'api.v1.files.download' => 'file.download_authorized',
            default => null,
        };

        if ($action !== null && $status >= 400) {
            $target = $request->route('user');
            $fileId = $request->route('file');
            $isFile = str_starts_with($action, 'file.');
            $this->audit->record(
                action: $action,
                actorId: $request->user('web')?->id,
                subjectType: $isFile ? 'file' : ($action === 'audit.viewed' ? 'audit' : 'staff'),
                subjectId: $isFile && is_string($fileId) && ctype_digit($fileId) ? (int) $fileId
                    : ($target instanceof User ? $target->id : null),
                outcome: $status >= 500 ? 'failed' : 'denied',
                httpStatus: $status,
            );
        }

        return $response;
    }
}
