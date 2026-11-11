<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Exceptions\WriteConflict;
use App\Http\Requests\Api\V1\PatientRecordRequest;
use App\Models\Patient;
use App\Models\PatientCategoryHistory;
use App\Models\PatientFlag;
use App\Models\PatientMergeRequest;
use App\Services\AuditTrail;
use App\Services\IdempotentCommands;
use App\Services\PatientRecordManagement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\ValidationException;

class PatientRecordController extends Controller
{
    public function flags(Request $request, int $patient, AuditTrail $audit): JsonResponse
    {
        Patient::query()->visibleTo($request->user('web'))->findOrFail($patient);
        $this->pagination($request);
        $page = PatientFlag::where('patient_id', $patient)->orderByDesc('id')->paginate($request->integer('per_page', 25));
        $page->setCollection($page->getCollection()->map(fn (PatientFlag $flag): array => [
            'id' => $flag->id, 'type' => $flag->type, 'reason' => $flag->reason, 'created_by' => $flag->created_by,
            'created_at' => $flag->created_at?->toISOString(), 'resolved_by' => $flag->resolved_by,
            'resolution_reason' => $flag->resolution_reason, 'resolved_at' => $flag->resolved_at?->toISOString(),
        ]));
        $audit->record('patient.flags_viewed', $request->user('web')->id, 'patient', $patient);

        return JsonResource::collection($page)->response();
    }

    public function cards(Request $request, int $patient, AuditTrail $audit): JsonResponse
    {
        Patient::query()->visibleTo($request->user('web'))->findOrFail($patient);
        $this->pagination($request);
        $page = PatientCategoryHistory::where('patient_id', $patient)->orderByDesc('id')->paginate($request->integer('per_page', 25));
        $page->setCollection($page->getCollection()->map(fn (PatientCategoryHistory $entry): array => [
            'id' => $entry->id, 'previous_category_id' => $entry->previous_category_id, 'new_category_id' => $entry->new_category_id,
            'previous_mrn' => $entry->previous_mrn, 'new_mrn' => $entry->new_mrn, 'reason' => $entry->reason,
            'changed_by' => $entry->changed_by, 'changed_at' => $entry->changed_at->toISOString(),
        ]));
        $audit->record('patient.card_history_viewed', $request->user('web')->id, 'patient', $patient);

        return JsonResource::collection($page)->response();
    }

    public function access(Request $request, int $patient, AuditTrail $audit): JsonResponse
    {
        $record = Patient::query()->findOrFail($patient);
        $audit->record('patient.access_state_viewed', $request->user('web')->id, 'patient', $patient);

        return response()->json(['data' => ['id' => $record->id, 'version' => $record->version, 'access_status' => $record->access_status]]);
    }

    public function merges(Request $request, int $patient, AuditTrail $audit): JsonResponse
    {
        Patient::query()->visibleTo($request->user('web'))->findOrFail($patient);
        $this->pagination($request);
        $page = PatientMergeRequest::with('target')->where('source_patient_id', $patient)->orderByDesc('id')->paginate($request->integer('per_page', 25));
        $page->setCollection($page->getCollection()->map(function (PatientMergeRequest $entry): array {
            $data = ['id' => $entry->id, 'source_patient_id' => $entry->source_patient_id, 'target_patient_id' => $entry->target_patient_id,
                'source_version' => $entry->source_version, 'target_version' => $entry->target_version,
                'requested_by' => $entry->requested_by, 'status' => $entry->status, 'version' => $entry->version,
                'reviewed_by' => $entry->reviewed_by, 'reviewed_at' => $entry->reviewed_at?->toISOString()];
            if ($entry->target?->access_status === 'active') {
                $data['reason'] = $entry->reason;
                $data['review_reason'] = $entry->review_reason;
            }

            return $data;
        }));
        $audit->record('patient.merge_requests_viewed', $request->user('web')->id, 'patient', $patient);

        return JsonResource::collection($page)->response();
    }

    public function write(Request $request, IdempotentCommands $commands, PatientRecordManagement $records): JsonResponse
    {
        return $commands->run($request, function () use ($request, $records): JsonResponse {
            $data = app(PatientRecordRequest::class)->validated();
            try {
                $receipt = $records->execute($request, $data);
            } catch (UniqueConstraintViolationException $exception) {
                if ($records->action($request) === 'cards.change') {
                    throw ValidationException::withMessages(['mrn' => ['The patient number is unavailable.']]);
                }
                if ($records->action($request) === 'merges.request') {
                    throw new WriteConflict('merge_pending', 'A merge request is already pending for this patient.');
                }

                throw $exception;
            }

            return response()->json(['data' => $receipt], in_array($receipt['operation'], ['flags.store', 'merges.request'], true) ? 201 : 200);
        });
    }

    private function pagination(Request $request): void
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
    }
}
