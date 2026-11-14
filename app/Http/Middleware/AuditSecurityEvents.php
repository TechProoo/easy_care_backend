<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditTrail;
use App\Services\PatientRecordManagement;
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
            'api.v1.prescriptions.store' => 'prescription.created',
            'api.v1.prescriptions.update' => 'prescription.updated',
            'api.v1.prescriptions.sign' => 'prescription.signed',
            'api.v1.prescriptions.amend' => 'prescription.amended',
            'api.v1.prescriptions.void' => 'prescription.voided',
            'api.v1.prescriptions.stop' => 'prescription.stopped',
            'api.v1.prescriptions.complete' => 'prescription.completed',
            'api.v1.prescriptions.index' => 'prescription.listed',
            'api.v1.prescriptions.show' => 'prescription.viewed',
            'api.v1.prescriptions.history' => 'prescription.history_viewed',
            'api.v1.patients.history' => 'patient.history_viewed',
            'api.v1.clinical.store' => 'clinical.created',
            'api.v1.clinical.update' => 'clinical.updated',
            'api.v1.clinical.sign' => 'clinical.signed',
            'api.v1.clinical.amend' => 'clinical.amended',
            'api.v1.clinical.void' => 'clinical.voided',
            'api.v1.clinical.index' => 'clinical.listed',
            'api.v1.clinical.show' => 'clinical.viewed',
            'api.v1.clinical.history' => 'clinical.history_viewed',
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
            'api.v1.patients.store' => 'patient.created',
            'api.v1.patients.update' => 'patient.updated',
            'api.v1.patients.index' => 'patient.listed',
            'api.v1.patients.show' => 'patient.viewed',
            'api.v1.patients.duplicates' => 'patient.duplicates_checked',
            'api.v1.encounters.store', 'api.v1.appointments.arrive' => 'encounter.opened',
            'api.v1.encounters.assign' => 'encounter.assigned',
            'api.v1.encounters.reassign' => 'encounter.reassigned',
            'api.v1.encounters.accept' => 'encounter.accepted',
            'api.v1.encounters.complete' => 'encounter.completed',
            'api.v1.encounters.cancel' => 'encounter.cancelled',
            'api.v1.encounters.index', 'api.v1.queues.index' => 'encounter.listed',
            'api.v1.encounters.show' => 'encounter.viewed',
            'api.v1.encounters.history', 'api.v1.encounters.assignments' => 'encounter.history_viewed',
            'api.v1.appointments.store' => 'appointment.created',
            'api.v1.appointments.update' => 'appointment.updated',
            'api.v1.appointments.confirm' => 'appointment.confirmed',
            'api.v1.appointments.cancel' => 'appointment.cancelled',
            'api.v1.appointments.no-show' => 'appointment.no_show',
            'api.v1.appointments.index' => 'appointment.listed',
            'api.v1.appointments.show' => 'appointment.viewed',
            'api.v1.appointments.history' => 'appointment.history_viewed',
            'api.v1.patients.records.flags.index' => 'patient.flags_viewed',
            'api.v1.patients.records.cards.index' => 'patient.card_history_viewed',
            'api.v1.patients.records.access.show' => 'patient.access_state_viewed',
            'api.v1.patients.records.merges.index' => 'patient.merge_requests_viewed',
            default => PatientRecordManagement::ACTIONS[str_replace('api.v1.patients.records.', '', $request->route()?->getName() ?? '')][1] ?? null,
        };

        if ($action !== null && $status >= 400) {
            $target = $request->route('user');
            $fileId = $request->route('file');
            $isClinical = str_starts_with($action, 'clinical.') || str_starts_with($action, 'prescription.');
            $clinicalId = $request->route('clinicalRecord');
            $isFile = str_starts_with($action, 'file.');
            $isPatient = str_starts_with($action, 'patient.');
            $isAppointment = str_starts_with($action, 'appointment.');
            $isEncounter = str_starts_with($action, 'encounter.');
            $encounterId = $request->route('encounter');
            $appointmentId = $request->route('appointment');
            $patientId = $request->route('patient');
            $this->audit->record(
                action: $action,
                actorId: $request->user('web')?->id,
                subjectType: $isClinical ? 'clinical' : ($isEncounter ? 'encounter' : ($isAppointment ? 'appointment' : ($isPatient ? 'patient' : ($isFile ? 'file' : ($action === 'audit.viewed' ? 'audit' : 'staff'))))),
                subjectId: $isClinical && is_string($clinicalId) && ctype_digit($clinicalId) ? (int) $clinicalId : ($isEncounter && is_string($encounterId) && ctype_digit($encounterId) ? (int) $encounterId
                    : ($isAppointment && is_string($appointmentId) && ctype_digit($appointmentId) ? (int) $appointmentId
                    : ($isPatient && is_string($patientId) && ctype_digit($patientId) ? (int) $patientId
                    : ($isFile && is_string($fileId) && ctype_digit($fileId) ? (int) $fileId
                    : ($target instanceof User ? $target->id : null))))),
                outcome: $status >= 500 ? 'failed' : 'denied',
                httpStatus: $status,
            );
        }

        return $response;
    }
}
