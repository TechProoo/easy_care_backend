<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClinicalCommandRequest;
use App\Http\Requests\Api\V1\ListClinicalRecordsRequest;
use App\Http\Resources\ClinicalResource;
use App\Http\Resources\ClinicalRevisionResource;
use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplateVersion;
use App\Models\Encounter;
use App\Services\AuditTrail;
use App\Services\ClinicalPayloads;
use App\Services\ClinicalRecords;
use App\Services\IdempotentCommands;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ClinicalController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => ['kinds' => ClinicalRecord::ENTRY_KINDS, 'sections' => ClinicalPayloads::SECTIONS,
            'physical_examination_fields' => ClinicalPayloads::EXAM_FIELDS, 'timezone' => config('appointments.timezone'),
            'blood_glucose_units' => ['mg/dL', 'mmol/L'], 'own_drafts_only' => true, 'closed_visits_read_only' => true,
            'co_signing' => false, 'signed_vitals_require_measurement' => true]]);
    }

    public function index(ListClinicalRecordsRequest $request, int $encounter, AuditTrail $audit): AnonymousResourceCollection
    {
        Encounter::query()->visibleTo($request->user('web'))->findOrFail($encounter);
        $query = ClinicalRecord::query()->visibleTo($request->user('web'))->where('encounter_id', $encounter)
            ->whereDoesntHave('amendment', fn (Builder $amendments): Builder => $amendments->finalized())->with(ClinicalRecords::LOAD)
            ->withExists(['amendment as superseded' => fn (Builder $amendments): Builder => $amendments->finalized()]);
        foreach (['kind', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        $audit->record('clinical.listed', $request->user('web')->id, 'clinical');

        return ClinicalResource::collection($query->orderByDesc('recorded_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    public function show(Request $request, int $encounter, int $clinicalRecord, AuditTrail $audit): ClinicalResource
    {
        $record = ClinicalRecord::query()->visibleTo($request->user('web'))->where('encounter_id', $encounter)
            ->with(ClinicalRecords::LOAD)->findOrFail($clinicalRecord);
        $audit->record('clinical.viewed', $request->user('web')->id, 'clinical', $record->id);

        return new ClinicalResource($record);
    }

    public function history(ListClinicalRecordsRequest $request, int $encounter, int $clinicalRecord, AuditTrail $audit): AnonymousResourceCollection
    {
        $record = ClinicalRecord::query()->visibleTo($request->user('web'))->where('encounter_id', $encounter)->findOrFail($clinicalRecord);
        $audit->record('clinical.history_viewed', $request->user('web')->id, 'clinical', $record->id);

        return ClinicalRevisionResource::collection($record->revisions()->with('actor:id,name')
            ->paginate($request->integer('per_page', config('api.pagination.per_page'))));
    }

    public function write(Request $request, ClinicalRecords $clinical, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $clinical): JsonResponse {
            $record = $clinical->execute($request, $request->user('web'), app(ClinicalCommandRequest::class)->validated());

            return (new ClinicalResource($record))->response()->setStatusCode(in_array(ClinicalRecords::action($request), ['store', 'amend'], true) ? 201 : 200);
        });
    }

    public function templates(ListClinicalRecordsRequest $request, ClinicalPayloads $payloads): JsonResponse
    {
        $query = ClinicalTemplateVersion::query()->with('template')->whereHas('template', fn (Builder $templates): Builder => $templates->where('is_active', true))
            ->whereNotExists(fn (QueryBuilder $versions): QueryBuilder => $versions->selectRaw('1')->from('clinical_template_versions as later')
                ->whereColumn('later.clinical_template_id', 'clinical_template_versions.clinical_template_id')
                ->whereColumn('later.version', '>', 'clinical_template_versions.version'));
        $page = $query->orderBy('clinical_template_id')->paginate($request->integer('per_page', config('api.pagination.per_page')));
        $data = $page->getCollection()->map(function (ClinicalTemplateVersion $version) use ($payloads): array {
            try {
                $fields = $payloads->definition($version);
            } catch (ValidationException) {
                $fields = null;
            }

            return ['id' => $version->id, 'template_id' => $version->clinical_template_id, 'code' => $version->template->code,
                'version' => $version->version, 'title' => $version->title, 'fields' => $fields === null ? null : (object) $fields,
                'supported' => $fields !== null, 'published_at' => $version->published_at->toISOString()];
        });

        return response()->json(['data' => $data, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }
}
