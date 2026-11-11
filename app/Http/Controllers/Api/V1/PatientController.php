<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckPatientDuplicatesRequest;
use App\Http\Requests\Api\V1\ListPatientsRequest;
use App\Http\Requests\Api\V1\StorePatientRequest;
use App\Http\Requests\Api\V1\UpdatePatientRequest;
use App\Http\Resources\PatientResource;
use App\Http\Resources\PatientSummaryResource;
use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\PatientIdentifier;
use App\Services\AuditTrail;
use App\Services\IdempotentCommands;
use App\Services\PatientRegistry;
use App\Services\PatientIdentityResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PatientController extends Controller
{
    public function index(ListPatientsRequest $request, AuditTrail $audit, PatientIdentityResolver $identities): AnonymousResourceCollection
    {
        $query = Patient::query()->visibleTo($request->user('web'))->with('category');
        $search = trim($request->validated('q', '') ?? '');
        foreach (preg_split('/\s+/u', $search, flags: PREG_SPLIT_NO_EMPTY) as $part) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $part).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("patients.first_name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("patients.last_name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("patients.middle_name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("patients.phone LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('identifiers', fn (Builder $query): Builder => $query->whereRaw("identifier LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        if ($request->filled('mrn')) {
            $identifier = PatientIdentifier::where('identifier', $request->validated('mrn'))->first();
            $canonicalId = null;
            if ($identifier !== null) {
                try {
                    $canonicalId = $identities->resolve($request->user('web'), $identifier->patient_id)->id;
                } catch (ModelNotFoundException) {
                    $canonicalId = null;
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 404) {
                        throw $exception;
                    }
                }
            }
            $query->whereKey($canonicalId ?? 0);
        }
        foreach (['category_id', 'date_of_birth'] as $field) {
            if ($request->filled($field)) {
                if ($field === 'date_of_birth' && DB::getDriverName() === 'sqlite') {
                    $query->whereDate($field, $request->validated($field));
                } else {
                    $query->where($field, $request->validated($field));
                }
            }
        }
        $sort = $request->validated('sort', 'last_name');
        $page = $query->orderBy('patients.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('patients.id')->paginate($request->integer('per_page', config('api.pagination.per_page')))
            ->appends($request->safe()->except('page'));
        $audit->record('patient.listed', $request->user('web')->id, 'patient');

        return PatientSummaryResource::collection($page);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => ['categories' => PatientCategory::query()->orderBy('name')->get(['id', 'code', 'name'])]]);
    }

    public function show(Request $request, int $patient, AuditTrail $audit, PatientIdentityResolver $identities): PatientResource
    {
        $record = $identities->resolve($request->user('web'), $patient)->load(['category', 'contacts', 'identifiers']);
        $audit->record('patient.viewed', $request->user('web')->id, 'patient', $record->id);

        return (new PatientResource($record))->additional(['meta' => ['requested_id' => $patient, 'canonical_id' => $record->id]]);
    }

    public function duplicates(CheckPatientDuplicatesRequest $request, PatientRegistry $registry, AuditTrail $audit): JsonResponse
    {
        $data = $request->validated();
        $exclude = isset($data['exclude_id']) ? (int) $data['exclude_id'] : null;
        if ($exclude !== null) {
            Patient::query()->visibleTo($request->user('web'))->findOrFail($exclude);
        }
        $candidates = $registry->candidates($request->user('web'), $data, $exclude);
        $audit->record('patient.duplicates_checked', $request->user('web')->id, 'patient');

        return response()->json(['data' => PatientSummaryResource::collection($candidates->take(20)),
            'meta' => ['has_more' => $candidates->count() > 20]]);
    }

    public function store(Request $request, PatientRegistry $registry, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $registry): JsonResponse {
            $data = app(StorePatientRequest::class)->validated();
            try {
                $patient = $registry->create($request->user('web'), $data);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['mrn' => ['The patient number is unavailable.']]);
            }

            return (new PatientResource($patient->load(['category', 'contacts', 'identifiers'])))->response()->setStatusCode(201);
        });
    }

    public function update(Request $request, int $patient, PatientRegistry $registry, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $patient, $registry): JsonResponse {
            $record = $registry->update($request->user('web'), $patient, app(UpdatePatientRequest::class)->validated());

            return (new PatientResource($record->load(['category', 'contacts', 'identifiers'])))->response();
        });
    }
}
