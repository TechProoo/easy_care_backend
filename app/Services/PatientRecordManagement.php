<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\PatientAccessEvent;
use App\Models\PatientCategoryHistory;
use App\Models\PatientFlag;
use App\Models\PatientMerge;
use App\Models\PatientMergeRequest;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use stdClass;

class PatientRecordManagement
{
    public const ACTIONS = [
        'flags.store' => ['patients.flags.manage', 'patient.flagged', 'patient_flags'],
        'flags.resolve' => ['patients.flags.manage', 'patient.flag_resolved', 'patient_flags'],
        'access.restrict' => ['patients.access.manage', 'patient.access_restricted', 'patient_access_events'],
        'access.restore' => ['patients.access.manage', 'patient.access_restored', 'patient_access_events'],
        'cards.change' => ['patients.cards.manage', 'patient.card_changed', 'patient_category_history'],
        'merges.request' => ['patients.merges.request', 'patient.merge_requested', 'patient_merge_requests'],
        'merges.approve' => ['patients.merges.approve', 'patient.merge_approved', 'patient_merge_requests'],
        'merges.reject' => ['patients.merges.approve', 'patient.merge_rejected', 'patient_merge_requests'],
        'merges.cancel' => ['patients.merges.request', 'patient.merge_cancelled', 'patient_merge_requests'],
    ];

    public function __construct(private AuditTrail $audit, private WriteTransactions $transactions) {}

    public static function isCommand(string $route): bool
    {
        return isset(self::ACTIONS[str_replace('api.v1.patients.records.', '', $route)]);
    }

    public function action(Request $request): string
    {
        return str_replace('api.v1.patients.records.', '', $request->route()->getName());
    }

    public function authorize(Request $request, User $actor): Patient
    {
        $action = $this->action($request);
        Gate::forUser($actor)->authorize('patients.view');
        Gate::forUser($actor)->authorize(self::ACTIONS[$action][0]);
        if (str_starts_with($action, 'flags.')) {
            Gate::forUser($actor)->authorize('patients.flags.view');
        } else {
            $password = $request->input('current_password');
            if (!is_string($password) || strlen($password) > 1024 || !Hash::check($password, $actor->password)) {
                throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
            }
        }
        $id = (int) $request->route('patient');
        $ids = [$id];
        $proposal = null;
        if ($action === 'merges.request') {
            $target = $request->input('target_patient_id');
            if ((is_int($target) || is_string($target)) && ctype_digit((string) $target)) {
                $ids[] = (int) $target;
            }
        } elseif (str_starts_with($action, 'merges.')) {
            $proposal = PatientMergeRequest::query()->where('source_patient_id', $id)->findOrFail($request->route('mergeRequest'));
            $ids[] = $proposal->target_patient_id;
        }
        $patients = Patient::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $patient = $patients->get($id);
        abort_if($patient === null, 404);
        if (str_starts_with($action, 'access.') || in_array($action, ['merges.reject', 'merges.cancel'], true)) {
            // Only a command receipt is returned for these administrative transitions.
        } elseif ($action === 'merges.approve' && $proposal?->status === 'approved') {
            abort_unless($patient->access_status === 'merged', 404);
            abort_unless($patients->get($proposal->target_patient_id)?->access_status === 'active', 404);
        } else {
            abort_unless($patient->access_status === 'active', 404);
            abort_if(PatientMerge::where('source_patient_id', $id)->exists(), 404);
        }
        if ($action === 'flags.resolve') {
            PatientFlag::where('patient_id', $id)->findOrFail($request->route('flag'));
        }
        $request->attributes->set('patient_record', $patient);

        return $patient;
    }

    /** @param array<string, mixed> $data @return array<string, int|string> */
    public function execute(Request $request, array $data): array
    {
        return $this->transactions->run(function () use ($request, $data): array {
            abort_if(Permission::where('code', 'staff.manage')->lockForUpdate()->first() === null, 503);
            abort_if(Permission::where('code', 'patients.create')->lockForUpdate()->first() === null, 503);
            $actor = User::query()->lockForUpdate()->findOrFail($request->user('web')->id);
            abort_unless($actor->is_active, 403);
            $patient = $this->authorize($request, $actor);
            $this->version($patient->version, (int) $data['version']);
            $action = $this->action($request);
            $recordId = match ($action) {
                'flags.store' => $this->flag($patient, $actor, $data),
                'flags.resolve' => $this->resolveFlag($patient, $actor, (int) $request->route('flag'), $data),
                'access.restrict', 'access.restore' => $this->access($patient, $actor, $action, $data),
                'cards.change' => $this->card($patient, $actor, $data),
                'merges.request' => $this->propose($patient, $actor, $data),
                default => $this->review($patient, $actor, (int) $request->route('mergeRequest'), $action, $data),
            };
            $patient->version++;
            $patient->save();
            $this->audit->record(self::ACTIONS[$action][1], $actor->id, 'patient', $patient->id,
                ['version' => $patient->version, 'record_id' => $recordId]);

            return $this->receipt($patient, $action, $recordId);
        });
    }

    public function replay(Request $request, User $actor, stdClass $record): JsonResponse
    {
        $patient = $this->authorize($request, $actor);
        if ($patient->id !== (int) $record->resource_id || $patient->version !== (int) $record->resource_version) {
            throw new WriteConflict('idempotency_outdated', 'The original operation completed, but this patient record has changed again. Reload its latest state.');
        }
        $action = $this->action($request);
        $column = str_starts_with($action, 'merges.') ? 'source_patient_id' : 'patient_id';
        abort_unless(DB::table(self::ACTIONS[$action][2])->where('id', $record->outcome_id)->where($column, $patient->id)->exists(), 404);
        if ($action === 'merges.approve') {
            $merge = PatientMerge::where('request_id', $record->outcome_id)->sole();
            $target = Patient::query()->lockForUpdate()->findOrFail($merge->target_patient_id);
            abort_unless($target->access_status === 'active', 404);
            if ($target->version !== $merge->target_version) {
                throw new WriteConflict('idempotency_outdated', 'The surviving patient has changed since this merge. Reload its latest record.');
            }
        }

        return response()->json(['data' => $this->receipt($patient, $action, (int) $record->outcome_id)]);
    }

    private function flag(Patient $patient, User $actor, array $data): int
    {
        return PatientFlag::query()->forceCreate(['patient_id' => $patient->id, 'type' => $data['type'], 'reason' => $data['reason'], 'created_by' => $actor->id])->id;
    }

    private function resolveFlag(Patient $patient, User $actor, int $id, array $data): int
    {
        $flag = PatientFlag::where('patient_id', $patient->id)->lockForUpdate()->findOrFail($id);
        if ($flag->resolved_at !== null) {
            throw new WriteConflict('flag_already_resolved', 'This flag was already resolved. Reload the record.');
        }
        $flag->forceFill(['resolved_by' => $actor->id, 'resolution_reason' => $data['reason'], 'resolved_at' => now('UTC')])->save();

        return $flag->id;
    }

    private function access(Patient $patient, User $actor, string $action, array $data): int
    {
        $restrict = $action === 'access.restrict';
        if ($patient->access_status !== ($restrict ? 'active' : 'restricted') || PatientMerge::where('source_patient_id', $patient->id)->exists()) {
            throw new WriteConflict('invalid_patient_transition', 'This access change is not valid for the current patient state. Reload it.');
        }
        $patient->access_status = $restrict ? 'restricted' : 'active';

        return PatientAccessEvent::query()->forceCreate(['patient_id' => $patient->id, 'action' => $restrict ? 'restrict' : 'restore',
            'reason' => $data['reason'], 'actor_id' => $actor->id, 'occurred_at' => now('UTC')])->id;
    }

    private function card(Patient $patient, User $actor, array $data): int
    {
        $mrn = $data['mrn'] ?? $patient->mrn;
        if ($patient->category_id === (int) $data['category_id'] && $patient->mrn === $mrn) {
            throw new WriteConflict('no_patient_change', 'Choose a different category or a new patient number.');
        }
        $id = PatientCategoryHistory::query()->forceCreate(['patient_id' => $patient->id,
            'previous_category_id' => $patient->category_id, 'new_category_id' => (int) $data['category_id'],
            'previous_mrn' => $patient->mrn, 'new_mrn' => $mrn, 'reason' => $data['reason'],
            'changed_by' => $actor->id, 'changed_at' => now('UTC')])->id;
        $patient->category_id = (int) $data['category_id'];
        $patient->mrn = $mrn;

        return $id;
    }

    private function propose(Patient $patient, User $actor, array $data): int
    {
        if ($patient->version >= 4294967294) {
            throw new WriteConflict('version_conflict', 'The patient version limit needs review before a merge can be requested.');
        }
        $target = Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($data['target_patient_id']);
        $this->version($target->version, (int) $data['target_version']);
        if ($target->id === $patient->id || PatientMerge::where('source_patient_id', $target->id)->exists()) {
            throw new WriteConflict('invalid_merge', 'Choose two distinct active patient identities.');
        }
        if (PatientMergeRequest::where('source_patient_id', $patient->id)->where('status', 'pending')->exists()) {
            throw new WriteConflict('merge_pending', 'A merge request is already pending for this patient. Review or cancel it first.');
        }

        return PatientMergeRequest::query()->forceCreate(['source_patient_id' => $patient->id, 'target_patient_id' => $target->id,
            'source_version' => $patient->version + 1, 'target_version' => $target->version,
            'requested_by' => $actor->id, 'reason' => $data['reason'], 'status' => 'pending', 'version' => 1])->id;
    }

    private function review(Patient $patient, User $actor, int $id, string $action, array $data): int
    {
        $proposal = PatientMergeRequest::where('source_patient_id', $patient->id)->lockForUpdate()->findOrFail($id);
        if ($proposal->status !== 'pending' || $proposal->version !== (int) $data['request_version']) {
            throw new WriteConflict('merge_already_reviewed', 'This merge request has changed or was already reviewed. Reload it.');
        }
        $cancel = $action === 'merges.cancel';
        abort_unless($cancel ? $proposal->requested_by === $actor->id : $proposal->requested_by !== $actor->id, 403);
        $approved = $action === 'merges.approve';
        $target = null;
        if ($approved) {
            $target = Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($proposal->target_patient_id);
            abort_unless($patient->access_status === 'active', 404);
            $this->version($patient->version, $proposal->source_version);
            $this->version($target->version, $proposal->target_version);
            $sourceIds = $this->lineage($patient->id, true);
            $targetIds = $this->lineage($target->id);
            if (Appointment::whereIn('patient_id', $sourceIds)->whereIn('status', ['booked', 'confirmed'])->exists()) {
                throw new WriteConflict('merge_appointments_pending', 'Resolve active bookings on the source patient before approving this merge.');
            }
            $related = DB::table('patient_relationships')->where(function (QueryBuilder $query) use ($sourceIds, $targetIds): void {
                $query->whereIn('patient_id', $sourceIds)->whereIn('related_patient_id', $targetIds);
            })->orWhere(function (QueryBuilder $query) use ($sourceIds, $targetIds): void {
                $query->whereIn('patient_id', $targetIds)->whereIn('related_patient_id', $sourceIds);
            })->exists();
            if (array_intersect($sourceIds, $targetIds) !== [] || $related
                || PatientFlag::whereIn('patient_id', $sourceIds)->whereNull('resolved_at')->exists()) {
                throw new WriteConflict('merge_review_required', 'Review unresolved flags, family links and identity history before merging these records.');
            }
        }
        $proposal->forceFill(['status' => $cancel ? 'cancelled' : ($approved ? 'approved' : 'rejected'), 'version' => 2,
            'reviewed_by' => $actor->id, 'review_reason' => $data['reason'], 'reviewed_at' => now('UTC')])->save();
        if ($approved) {
            PatientMerge::query()->forceCreate(['request_id' => $proposal->id, 'source_patient_id' => $patient->id,
                'target_patient_id' => $target->id, 'source_version' => $patient->version + 1, 'target_version' => $target->version + 1,
                'approved_by' => $actor->id, 'reason' => $proposal->reason, 'approved_at' => now('UTC')]);
            $target->version++;
            $target->save();
            $patient->access_status = 'merged';
        }

        return $proposal->id;
    }

    /** @return list<int> */
    private function lineage(int $root, bool $addingEdge = false): array
    {
        $ids = [$root];
        $frontier = [$root];
        for ($depth = 0; $depth < ($addingEdge ? 63 : 64); $depth++) {
            $sources = PatientMerge::whereIn('target_patient_id', $frontier)->pluck('source_patient_id')->all();
            if ($sources === []) {
                return $ids;
            }
            if (array_intersect($ids, $sources) !== []) {
                break;
            }
            $ids = [...$ids, ...$sources];
            $frontier = $sources;
        }
        throw new WriteConflict('invalid_merge', 'The identity history needs review before another merge.');
    }

    private function version(int $actual, int $expected): void
    {
        if ($actual !== $expected || $actual >= 4294967295) {
            throw new WriteConflict('version_conflict', 'This patient record has changed. Reload it before continuing.');
        }
    }

    private function receipt(Patient $patient, string $action, int $recordId): array
    {
        return ['id' => $patient->id, 'version' => $patient->version, 'operation' => $action, 'record_id' => $recordId];
    }
}
