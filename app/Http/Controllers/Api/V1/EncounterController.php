<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EncounterCommandRequest;
use App\Http\Requests\Api\V1\ListEncountersRequest;
use App\Http\Resources\EncounterResource;
use App\Http\Resources\DoctorAssignmentResource;
use App\Http\Resources\EncounterRevisionResource;
use App\Models\Encounter;
use App\Services\AuditTrail;
use App\Services\EncounterFlow;
use App\Services\IdempotentCommands;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EncounterController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => ['timezone' => config('appointments.timezone'),
            'care_settings' => ['OPD', 'IPD', 'EMERGENCY'], 'visit_types' => config('appointments.visit_types'),
            'queues' => ['unassigned', 'waiting', 'in_progress'], 'queue_order' => ['opened_at', 'id'],
            'identity_verification_required' => true, 'current_clinicians_per_visit' => 1]]);
    }

    public function index(ListEncountersRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = Encounter::query()->visibleTo($request->user('web'))->with(['patient', 'currentAssignment']);
        if ($request->route()->getName() === 'api.v1.queues.index') {
            $query->whereIn('status', ['open', 'in_progress']);
        }
        foreach (['status', 'care_setting', 'patient_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        if ($request->filled('doctor_id')) {
            $query->whereHas('currentAssignment', fn (Builder $assignment): Builder => $assignment->where('doctor_id', $request->integer('doctor_id')));
        }
        if ($request->filled('queue')) {
            $queue = $request->validated('queue');
            $query->whereIn('status', ['open', 'in_progress']);
            if ($queue === 'unassigned') {
                $query->whereDoesntHave('currentAssignment');
            } else {
                $query->whereHas('currentAssignment', fn (Builder $assignment): Builder => $assignment->where('status', $queue === 'waiting' ? 'assigned' : 'accepted'));
            }
        }
        if ($request->filled('from_date')) {
            $query->where('opened_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $request->validated('from_date'), config('appointments.timezone'))->utc()->format('Y-m-d H:i:s.u'));
        }
        if ($request->filled('to_date')) {
            $query->where('opened_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $request->validated('to_date'), config('appointments.timezone'))->addDay()->utc()->format('Y-m-d H:i:s.u'));
        }
        $page = $query->orderBy('opened_at')->orderBy('id')->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated());
        $audit->record('encounter.listed', $request->user('web')->id, 'encounter');

        return EncounterResource::collection($page)->additional(['timezone' => config('appointments.timezone')]);
    }

    public function show(Request $request, int $encounter, AuditTrail $audit): EncounterResource
    {
        $visit = Encounter::query()->visibleTo($request->user('web'))->with(['patient', 'currentAssignment'])->findOrFail($encounter);
        $audit->record('encounter.viewed', $request->user('web')->id, 'encounter', $visit->id);

        return new EncounterResource($visit);
    }

    public function history(ListEncountersRequest $request, int $encounter, AuditTrail $audit): AnonymousResourceCollection
    {
        $visit = Encounter::query()->visibleTo($request->user('web'))->findOrFail($encounter);
        $audit->record('encounter.history_viewed', $request->user('web')->id, 'encounter', $visit->id);

        return EncounterRevisionResource::collection($visit->revisions()->paginate($request->integer('per_page', config('api.pagination.per_page'))));
    }

    public function write(Request $request, EncounterFlow $flow, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $flow): JsonResponse {
            $visit = $flow->execute($request, $request->user('web'), app(EncounterCommandRequest::class)->validated());
            $visit->load(['patient', 'currentAssignment']);

            return (new EncounterResource($visit))->response()->setStatusCode(in_array(EncounterFlow::action($request), ['store', 'arrive'], true) ? 201 : 200);
        });
    }

    public function assignments(ListEncountersRequest $request, int $encounter, AuditTrail $audit): AnonymousResourceCollection
    {
        $visit = Encounter::query()->visibleTo($request->user('web'))->findOrFail($encounter);
        $audit->record('encounter.history_viewed', $request->user('web')->id, 'encounter', $visit->id);

        return DoctorAssignmentResource::collection($visit->assignments()->paginate($request->integer('per_page', config('api.pagination.per_page'))));
    }
}
