<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPatientHistoryRequest;
use App\Http\Resources\PatientHistoryResource;
use App\Models\ClinicalRecord;
use App\Models\Patient;
use App\Services\AuditTrail;
use App\Services\ClinicalRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PatientHistoryController extends Controller
{
    public function __invoke(ListPatientHistoryRequest $request, int $patient, AuditTrail $audit): AnonymousResourceCollection
    {
        $actor = $request->user('web');
        Patient::query()->visibleTo($actor)->findOrFail($patient);
        abort_unless($actor->hasPermission('clinical.view') || $actor->hasPermission('prescriptions.view'), 403);
        $kind = $request->validated('kind');
        if ($kind !== null) {
            abort_unless($actor->hasPermission($kind === 'prescription' ? 'prescriptions.view' : 'clinical.view'), 403);
        }
        $query = ClinicalRecord::query()->where('patient_id', $patient)->finalized()
            ->where(fn (Builder $records): Builder => $records->where(fn (Builder $clinical): Builder => $clinical->visibleTo($actor))
                ->orWhere(fn (Builder $prescriptions): Builder => $prescriptions->prescriptionsVisibleTo($actor)))
            ->with([...ClinicalRecords::LOAD, 'prescription.items', 'encounter:id,patient_id,status,care_setting,opened_at,closed_at'])
            ->withExists(['amendment as superseded' => fn (Builder $amendments): Builder => $amendments->finalized()]);
        if (! $request->boolean('include_superseded')) {
            $query->whereDoesntHave('amendment', fn (Builder $amendments): Builder => $amendments->finalized());
        }
        foreach (['kind', 'status', 'encounter_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        $audit->record('patient.history_viewed', $actor->id, 'patient', $patient);

        return PatientHistoryResource::collection($query->orderByDesc('recorded_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }
}
