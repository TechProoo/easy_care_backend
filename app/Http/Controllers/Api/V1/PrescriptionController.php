<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPrescriptionsRequest;
use App\Http\Requests\Api\V1\PrescriptionCommandRequest;
use App\Http\Resources\ClinicalResource;
use App\Http\Resources\ClinicalRevisionResource;
use App\Models\ClinicalRecord;
use App\Models\Encounter;
use App\Services\AuditTrail;
use App\Services\ClinicalRecords;
use App\Services\IdempotentCommands;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PrescriptionController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => ['order_statuses' => ['active', 'stopped', 'completed'],
            'status_transitions' => ['active' => ['stopped', 'completed'], 'stopped' => [], 'completed' => []],
            'max_items' => 100, 'medication_source' => 'staff_entered_snapshot', 'catalog_required' => false,
            'required_signing_fields' => ['medication_name', 'dose', 'route', 'frequency'],
            'own_drafts_only' => true, 'closed_visits_read_only' => true, 'co_signing' => false,
            'dose_recommendations' => false, 'interaction_checking' => false, 'timezone' => config('appointments.timezone')]]);
    }

    public function index(ListPrescriptionsRequest $request, int $encounter, AuditTrail $audit): AnonymousResourceCollection
    {
        $actor = $request->user('web');
        Encounter::query()->visibleTo($actor)->findOrFail($encounter);
        $query = ClinicalRecord::query()->prescriptionsVisibleTo($actor)->where('encounter_id', $encounter)
            ->whereDoesntHave('amendment', fn (Builder $query): Builder => $query->finalized())
            ->with(ClinicalRecords::relationsFor($request))
            ->withExists(['amendment as superseded' => fn (Builder $query): Builder => $query->finalized()]);
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('order_status')) {
            $query->whereHas('prescription', fn (Builder $query): Builder => $query->where('order_status', $request->validated('order_status')));
        }
        $audit->record('prescription.listed', $actor->id, 'clinical');

        return ClinicalResource::collection($query->orderByDesc('recorded_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    public function show(Request $request, int $encounter, int $clinicalRecord, AuditTrail $audit): ClinicalResource
    {
        $record = ClinicalRecord::query()->prescriptionsVisibleTo($request->user('web'))->where('encounter_id', $encounter)
            ->with(ClinicalRecords::relationsFor($request))->findOrFail($clinicalRecord);
        $audit->record('prescription.viewed', $request->user('web')->id, 'clinical', $record->id);

        return new ClinicalResource($record);
    }

    public function history(ListPrescriptionsRequest $request, int $encounter, int $clinicalRecord, AuditTrail $audit): AnonymousResourceCollection
    {
        $record = ClinicalRecord::query()->prescriptionsVisibleTo($request->user('web'))->where('encounter_id', $encounter)->findOrFail($clinicalRecord);
        $audit->record('prescription.history_viewed', $request->user('web')->id, 'clinical', $record->id);

        return ClinicalRevisionResource::collection($record->revisions()->with('actor:id,name')
            ->paginate($request->integer('per_page', config('api.pagination.per_page'))));
    }

    public function write(Request $request, ClinicalRecords $records, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $records): JsonResponse {
            $record = $records->execute($request, $request->user('web'), app(PrescriptionCommandRequest::class)->validated());

            return (new ClinicalResource($record))->response()->setStatusCode(in_array(ClinicalRecords::action($request), ['store', 'amend'], true) ? 201 : 200);
        });
    }
}
