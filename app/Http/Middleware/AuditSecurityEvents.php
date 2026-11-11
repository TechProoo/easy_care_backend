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
            default => null,
        };

        if ($action !== null && $status >= 400) {
            $target = $request->route('user');
            $this->audit->record(
                action: $action,
                actorId: $request->user('web')?->id,
                subjectType: $action === 'audit.viewed' ? 'audit' : 'staff',
                subjectId: $target instanceof User ? $target->id : null,
                outcome: $status >= 500 ? 'failed' : 'denied',
                httpStatus: $status,
            );
        }

        return $response;
    }
}
