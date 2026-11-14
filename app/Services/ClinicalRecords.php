<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\ClinicalEntry;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalRecord;
use App\Models\ClinicalRevision;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\User;
use App\Models\VitalSign;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ClinicalRecords
{
    public const COMMANDS = ['api.v1.clinical.store', 'api.v1.clinical.update', 'api.v1.clinical.sign',
        'api.v1.clinical.amend', 'api.v1.clinical.void'];

    public const LOAD = ['vitals', 'entry.templateVersion.template', 'diagnosis', 'procedure', 'author:id,name', 'finalizedBy:id,name'];

    public function __construct(private WriteTransactions $transactions, private ClinicalPayloads $payloads, private AuditTrail $audit) {}

    public static function isCommand(string $route): bool
    {
        return in_array($route, self::COMMANDS, true);
    }

    public static function action(Request $request): string
    {
        return substr($request->route()->getName(), strrpos($request->route()->getName(), '.') + 1);
    }

    public function authorize(Request $request, User $actor): void
    {
        abort_unless($actor->is_active, 403);
        foreach (['patients.view', 'encounters.view', 'clinical.view', 'clinical.write'] as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
        $action = self::action($request);
        if (in_array($action, ['sign', 'amend'], true)) {
            Gate::forUser($actor)->authorize('clinical.sign');
        }
        if ($action === 'amend') {
            Gate::forUser($actor)->authorize('clinical.amend');
        }
        if ($action === 'void') {
            Gate::forUser($actor)->authorize('clinical.void');
        }
        if (in_array($action, ['sign', 'amend', 'void'], true)) {
            $password = $request->input('current_password');
            if (! is_string($password) || strlen($password) > 1024 || ! Hash::check($password, $actor->password)) {
                throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
            }
        }
        $patientId = Encounter::query()->whereKey($request->route('encounter'))->value('patient_id');
        Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($patientId);
        $visit = Encounter::query()->visibleTo($actor)->lockForUpdate()->findOrFail($request->route('encounter'));
        $request->attributes->set('clinical_encounter', $visit);
        if ($action !== 'store') {
            $record = ClinicalRecord::query()->visibleTo($actor)->where('encounter_id', $visit->id)
                ->lockForUpdate()->findOrFail($request->route('clinicalRecord'));
            if (in_array($action, ['update', 'sign'], true) || ($action === 'void' && $record->status === 'draft')) {
                abort_unless($record->authored_by === $actor->id, 403);
            }
            if ($record->amends_id !== null && in_array($action, ['update', 'sign'], true)) {
                Gate::forUser($actor)->authorize('clinical.amend');
            }
            if ($action === 'void' && $record->status === 'signed') {
                Gate::forUser($actor)->authorize('clinical.amend');
                Gate::forUser($actor)->authorize('clinical.sign');
            }
            $request->attributes->set('clinical_record', $record);
        }
    }

    /** @param array<string, mixed> $data */
    public function execute(Request $request, User $actor, array $data): ClinicalRecord
    {
        return $this->transactions->run(function () use ($request, $actor, $data): ClinicalRecord {
            abort_if(Permission::query()->where('code', 'staff.manage')->lockForUpdate()->first() === null, 503);
            abort_if(Permission::query()->where('code', 'patients.create')->lockForUpdate()->first() === null, 503);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $this->authorize($request, $actor);
            $visit = $request->attributes->get('clinical_encounter');
            $this->version($visit->version, $data['encounter_version']);
            if (! in_array($visit->status, ['open', 'in_progress'], true)) {
                throw new WriteConflict('clinical_encounter_closed', 'Completed and cancelled visits are read-only.');
            }
            $record = $request->attributes->get('clinical_record');
            $time = now('UTC');
            if ($time->lessThan($visit->opened_at) || ($record !== null && ($time->lessThan($record->recorded_at)
                || ($record->finalized_at !== null && $time->lessThan($record->finalized_at))))) {
                throw new WriteConflict('clinical_clock_conflict', 'The server time precedes an existing clinical event. Check the server clock.');
            }
            $action = self::action($request);
            if ($record !== null) {
                $this->version($record->version, $data['version']);
            }
            if ($action === 'store') {
                $payload = $this->payloads->validate($data['kind'], $data['payload'], $visit, $actor, false);
                $record = $this->draft($visit, $actor, $data['kind'], $time);
                $this->writePayload($record, $payload);
                $this->record($record, 'created');
            } elseif ($action === 'update') {
                $this->draftOnly($record);
                $payload = $this->payloads->validate($record->kind, $data['payload'], $visit, $actor, false, $record);
                if ($payload == ClinicalPayloads::stored($record)) {
                    throw new WriteConflict('clinical_unchanged', 'No clinical values have changed.');
                }
                $this->writePayload($record, $payload);
                $record->forceFill(['version' => $record->version + 1])->save();
                $this->record($record, 'updated');
            } elseif ($action === 'sign') {
                $this->draftOnly($record);
                $this->payloads->validate($record->kind, [], $visit, $actor, true, $record);
                $record->forceFill(['status' => 'signed', 'version' => $record->version + 1,
                    'finalized_by' => $actor->id, 'finalized_at' => $time])->save();
                $this->record($record, 'signed');
            } elseif ($action === 'amend') {
                $this->signedHead($record);
                $payload = $this->payloads->validate($record->kind, $data['payload'], $visit, $actor, true, $record);
                if ($payload == ClinicalPayloads::stored($record)) {
                    throw new WriteConflict('clinical_unchanged', 'Change the clinical values before submitting a correction.');
                }
                $replacement = $this->draft($visit, $actor, $record->kind, $time, $record, $data['reason']);
                $this->writePayload($replacement, $payload);
                $this->record($replacement, 'created', audit: false);
                $replacement->forceFill(['status' => 'signed', 'version' => 2, 'finalized_by' => $actor->id, 'finalized_at' => $time])->save();
                $this->record($replacement, 'amended', $data['reason']);
                $record = $replacement;
            } else {
                if ($record->status === 'draft') {
                    if ($record->amends_id !== null) {
                        throw new WriteConflict('clinical_pending_amendment', 'A pending amendment cannot be discarded as a retraction. Review this record before continuing.');
                    }
                } else {
                    $this->signedHead($record);
                    $record = $this->draft($visit, $actor, $record->kind, $time, $record, $data['reason']);
                    $this->record($record, 'created', audit: false);
                }
                $record->forceFill(['status' => 'voided', 'version' => $record->version + 1,
                    'finalized_by' => $actor->id, 'finalized_at' => $time, 'void_reason' => $data['reason']])->save();
                $this->record($record, 'voided', $data['reason']);
            }

            return $record->refresh()->load(self::LOAD);
        });
    }

    /** @return array<string, mixed> */
    public static function snapshot(ClinicalRecord $record): array
    {
        return $record->only(['id', 'patient_id', 'encounter_id', 'kind', 'status', 'version', 'authored_by', 'finalized_by',
            'amends_id', 'amendment_reason', 'void_reason']) + ['recorded_at' => $record->recorded_at->toISOString(),
            'finalized_at' => $record->finalized_at?->toISOString(),
            'payload' => in_array($record->kind, ClinicalRecord::ENTRY_KINDS, true) ? ClinicalPayloads::stored($record) : []];
    }

    private function draft(Encounter $visit, User $actor, string $kind, CarbonInterface $time, ?ClinicalRecord $original = null, ?string $reason = null): ClinicalRecord
    {
        $record = new ClinicalRecord;
        $record->forceFill(['patient_id' => $visit->patient_id, 'encounter_id' => $visit->id, 'kind' => $kind,
            'authored_by' => $actor->id, 'recorded_at' => $time, 'amends_id' => $original?->id, 'amendment_reason' => $reason])->save();

        return $record;
    }

    /** @param array<string, mixed> $payload */
    private function writePayload(ClinicalRecord $record, array $payload): void
    {
        $class = match ($record->kind) { 'vitals' => VitalSign::class, 'entry' => ClinicalEntry::class,
            'diagnosis' => Diagnosis::class, 'procedure' => ClinicalProcedure::class };
        $row = $class::query()->find($record->id) ?? new $class;
        $row->forceFill(['clinical_record_id' => $record->id] + $payload)->save();
        foreach (['vitals', 'entry', 'diagnosis', 'procedure'] as $relation) {
            $record->unsetRelation($relation);
        }
    }

    private function record(ClinicalRecord $record, string $operation, ?string $reason = null, bool $audit = true): void
    {
        $revision = new ClinicalRevision;
        $revision->forceFill(['clinical_record_id' => $record->id, 'version' => $record->version, 'operation' => $operation,
            'actor_id' => $record->authored_by, 'reason' => $reason, 'snapshot' => self::snapshot($record), 'recorded_at' => now('UTC')])->save();
        if ($audit) {
            $this->audit->record('clinical.'.$operation, $record->authored_by, 'clinical', $record->id,
                ['version' => $record->version, 'record_id' => $revision->id]);
        }
    }

    private function draftOnly(ClinicalRecord $record): void
    {
        if ($record->status !== 'draft') {
            throw new WriteConflict('clinical_record_finalized', 'Signed and voided clinical records cannot be overwritten.');
        }
    }

    private function signedHead(ClinicalRecord $record): void
    {
        if ($record->status !== 'signed' || $record->amendment()->lockForUpdate()->exists()) {
            throw new WriteConflict('clinical_not_current', 'Choose the latest signed clinical record before correcting or retracting it.');
        }
    }

    private function version(int $actual, mixed $expected): void
    {
        if ($actual !== (int) $expected) {
            throw new WriteConflict('version_conflict', 'This record or visit changed. Reload it before submitting again.');
        }
    }
}
