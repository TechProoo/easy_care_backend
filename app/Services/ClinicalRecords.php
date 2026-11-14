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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ClinicalRecords
{
    public const COMMANDS = ['api.v1.clinical.store', 'api.v1.clinical.update', 'api.v1.clinical.sign',
        'api.v1.clinical.amend', 'api.v1.clinical.void', 'api.v1.prescriptions.store', 'api.v1.prescriptions.update',
        'api.v1.prescriptions.sign', 'api.v1.prescriptions.amend', 'api.v1.prescriptions.void',
        'api.v1.prescriptions.stop', 'api.v1.prescriptions.complete'];

    public const LOAD = ['vitals', 'entry.templateVersion.template', 'diagnosis', 'procedure', 'author:id,name', 'finalizedBy:id,name'];

    public function __construct(private WriteTransactions $transactions, private ClinicalPayloads $payloads,
        private AuditTrail $audit, private PrescriptionPayloads $prescriptions) {}

    public static function isPrescription(Request $request): bool
    {
        return str_starts_with($request->route()->getName(), 'api.v1.prescriptions.');
    }

    public static function visibleFor(Request $request, User $actor): Builder
    {
        return self::isPrescription($request) ? ClinicalRecord::query()->prescriptionsVisibleTo($actor)
            : ClinicalRecord::query()->visibleTo($actor);
    }

    /** @return list<string> */
    public static function relationsFor(Request $request): array
    {
        return self::isPrescription($request) ? ['prescription.items', 'author:id,name', 'finalizedBy:id,name'] : self::LOAD;
    }

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
        $prefix = self::isPrescription($request) ? 'prescriptions' : 'clinical';
        foreach (['patients.view', 'encounters.view', $prefix.'.view', $prefix.'.write'] as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
        $action = self::action($request);
        if (in_array($action, ['sign', 'amend', 'stop', 'complete'], true)) {
            Gate::forUser($actor)->authorize($prefix.'.sign');
        }
        if (in_array($action, ['amend', 'stop', 'complete'], true)) {
            Gate::forUser($actor)->authorize($prefix.'.amend');
        }
        if ($action === 'void') {
            Gate::forUser($actor)->authorize($prefix.'.void');
        }
        if (in_array($action, ['sign', 'amend', 'void', 'stop', 'complete'], true)) {
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
            $record = self::visibleFor($request, $actor)->where('encounter_id', $visit->id)
                ->lockForUpdate()->findOrFail($request->route('clinicalRecord'));
            if (in_array($action, ['update', 'sign'], true) || ($action === 'void' && $record->status === 'draft')) {
                abort_unless($record->authored_by === $actor->id, 403);
            }
            if ($record->amends_id !== null && in_array($action, ['update', 'sign'], true)) {
                Gate::forUser($actor)->authorize($prefix.'.amend');
            }
            if ($action === 'void' && $record->status === 'signed') {
                Gate::forUser($actor)->authorize($prefix.'.amend');
                Gate::forUser($actor)->authorize($prefix.'.sign');
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
                $kind = self::isPrescription($request) ? 'prescription' : $data['kind'];
                $payload = $this->validatePayload($kind, $data['payload'], $visit, $actor, false);
                $record = $this->draft($visit, $actor, $kind, $time);
                $this->writePayload($record, $payload);
                $this->record($record, 'created');
            } elseif ($action === 'update') {
                $this->draftOnly($record);
                $payload = $this->validatePayload($record->kind, $data['payload'], $visit, $actor, false, $record);
                if ($payload == self::storedPayload($record)) {
                    throw new WriteConflict('clinical_unchanged', 'No clinical values have changed.');
                }
                $this->writePayload($record, $payload);
                $record->forceFill(['version' => $record->version + 1])->save();
                $this->record($record, 'updated');
            } elseif ($action === 'sign') {
                $this->draftOnly($record);
                $this->validatePayload($record->kind, [], $visit, $actor, true, $record);
                $record->forceFill(['status' => 'signed', 'version' => $record->version + 1,
                    'finalized_by' => $actor->id, 'finalized_at' => $time])->save();
                $this->record($record, 'signed');
            } elseif (in_array($action, ['amend', 'stop', 'complete'], true)) {
                $this->signedHead($record);
                if ($action === 'amend') {
                    $payload = $this->validatePayload($record->kind, $data['payload'], $visit, $actor, true, $record);
                } else {
                    $payload = PrescriptionPayloads::stored($record);
                    if (($payload['order_status'] ?? null) !== 'active') {
                        throw new WriteConflict('prescription_status_finalized', 'Only a current active prescription can be stopped or completed.');
                    }
                    $payload['order_status'] = $action === 'stop' ? 'stopped' : 'completed';
                }
                if ($payload == self::storedPayload($record)) {
                    throw new WriteConflict('clinical_unchanged', 'Change the clinical values before submitting a correction.');
                }
                $replacement = $this->draft($visit, $actor, $record->kind, $time, $record, $data['reason']);
                $this->writePayload($replacement, $payload);
                $this->record($replacement, 'created', audit: false);
                $replacement->forceFill(['status' => 'signed', 'version' => 2, 'finalized_by' => $actor->id, 'finalized_at' => $time])->save();
                $this->record($replacement, 'amended', $data['reason'], auditAction: $action === 'amend' ? null : 'prescription.'.($action === 'stop' ? 'stopped' : 'completed'));
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

            return $record->refresh()->load(self::relationsFor($request));
        });
    }

    /** @return array<string, mixed> */
    public static function snapshot(ClinicalRecord $record): array
    {
        return $record->only(['id', 'patient_id', 'encounter_id', 'kind', 'status', 'version', 'authored_by', 'finalized_by',
            'amends_id', 'amendment_reason', 'void_reason']) + ['recorded_at' => $record->recorded_at->toISOString(),
            'finalized_at' => $record->finalized_at?->toISOString(),
            'payload' => self::storedPayload($record)];
    }

    /** @return array<string, mixed> */
    public static function storedPayload(ClinicalRecord $record): array
    {
        return $record->kind === 'prescription' ? PrescriptionPayloads::stored($record) : ClinicalPayloads::stored($record);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validatePayload(string $kind, array $input, Encounter $visit, User $actor, bool $sign, ?ClinicalRecord $original = null): array
    {
        return $kind === 'prescription' ? $this->prescriptions->validate($input, $sign, $original)
            : $this->payloads->validate($kind, $input, $visit, $actor, $sign, $original);
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
        if ($record->kind === 'prescription') {
            $this->prescriptions->write($record, $payload);

            return;
        }
        $class = match ($record->kind) { 'vitals' => VitalSign::class, 'entry' => ClinicalEntry::class,
            'diagnosis' => Diagnosis::class, 'procedure' => ClinicalProcedure::class };
        $row = $class::query()->find($record->id) ?? new $class;
        $row->forceFill(['clinical_record_id' => $record->id] + $payload)->save();
        foreach (['vitals', 'entry', 'diagnosis', 'procedure'] as $relation) {
            $record->unsetRelation($relation);
        }
    }

    private function record(ClinicalRecord $record, string $operation, ?string $reason = null, bool $audit = true, ?string $auditAction = null): void
    {
        $revision = new ClinicalRevision;
        $revision->forceFill(['clinical_record_id' => $record->id, 'version' => $record->version, 'operation' => $operation,
            'actor_id' => $record->authored_by, 'reason' => $reason, 'snapshot' => self::snapshot($record), 'recorded_at' => now('UTC')])->save();
        if ($audit) {
            $prefix = $record->kind === 'prescription' ? 'prescription' : 'clinical';
            $this->audit->record($auditAction ?? $prefix.'.'.$operation, $record->authored_by, 'clinical', $record->id,
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
