<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\Permission;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PatientRegistry
{
    public const DEMOGRAPHICS = ['first_name', 'last_name', 'middle_name', 'sex', 'date_of_birth', 'dob_estimated',
        'email', 'phone', 'marital_status', 'religion', 'occupation', 'state_of_origin', 'address'];

    public function __construct(private AuditTrail $audit, private WriteTransactions $transactions) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Patient
    {
        return $this->locked($actor, function (User $actor) use ($data): Patient {
            Gate::forUser($actor)->authorize('create', Patient::class);
            $this->reviewDuplicates($actor, $data);
            PatientCategory::query()->findOrFail($data['category_id']);
            $patient = new Patient;
            $patient->fill(collect($data)->only(self::DEMOGRAPHICS)->all());
            $patient->forceFill(['mrn' => $data['mrn'], 'category_id' => (int) $data['category_id'], 'created_by' => $actor->id]);
            $patient->save();
            $this->contacts($patient, $data['contacts'] ?? []);
            $this->audit->record('patient.created', $actor->id, 'patient', $patient->id, [
                'version' => $patient->version, 'category_id' => $patient->category_id,
                'contact_count' => count($data['contacts'] ?? []),
                'duplicates_acknowledged' => ($data['acknowledged_duplicate_ids'] ?? []) !== [],
            ]);

            return $patient;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, int $id, array $data): Patient
    {
        return $this->locked($actor, function (User $actor) use ($id, $data): Patient {
            $patient = Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('update', $patient);
            if ($patient->version !== (int) $data['version']) {
                throw new WriteConflict('version_conflict', 'This patient record has changed. Reload it before editing again.');
            }
            $patient->fill(collect($data)->only(self::DEMOGRAPHICS)->all());
            if ($patient->isDirty(['first_name', 'last_name', 'date_of_birth', 'phone'])) {
                $this->reviewDuplicates($actor, array_replace($patient->getAttributes(), $data), $patient->id);
            }
            $changed = array_keys($patient->getDirty());
            $contactsChanged = array_key_exists('contacts', $data);
            if ($contactsChanged) {
                $this->contacts($patient, $data['contacts']);
                $changed[] = 'contacts';
            }
            $patient->version++;
            $patient->save();
            $this->audit->record('patient.updated', $actor->id, 'patient', $patient->id, [
                'version' => $patient->version, 'changed_fields' => $changed, 'contacts_changed' => $contactsChanged,
            ]);

            return $patient;
        });
    }

    /**
     * Candidate matching is advisory: exact normalized name/date or an exact phone match.
     * Scope is applied before matching; no hidden record identifiers reach a response.
     *
     * @param array<string, mixed> $data
     * @return Collection<int, Patient>
     */
    public function candidates(User $actor, array $data, ?int $exclude = null): Collection
    {
        $date = $data['date_of_birth'] ?? null;
        $date = $date === null ? null : substr($date, 0, 10);
        $phone = $data['phone'] ?? null;
        if ($date === null && $phone === null) {
            return collect();
        }
        $first = $this->nameKey($data['first_name']);
        $last = $this->nameKey($data['last_name']);
        $query = Patient::query()->visibleTo($actor)->with('category')
            ->when($exclude !== null, fn (Builder $query): Builder => $query->where('patients.id', '<>', $exclude))
            ->where(function (Builder $query) use ($date, $phone): void {
                $query->whereRaw('1 = 0');
                if ($date !== null) {
                    if (DB::getDriverName() === 'sqlite') {
                        $query->orWhereDate('date_of_birth', $date);
                    } else {
                        $query->orWhere('date_of_birth', $date);
                    }
                }
                if ($phone !== null) {
                    $query->orWhereIn('phone', [$phone, ltrim($phone, '+')]);
                }
            });

        return $query->lazyById(200)->filter(fn (Patient $patient): bool =>
            ($date !== null && $patient->date_of_birth?->format('Y-m-d') === $date
                && $this->nameKey($patient->first_name) === $first && $this->nameKey($patient->last_name) === $last)
            || ($phone !== null && ltrim((string) $patient->phone, '+') === ltrim($phone, '+')))
            ->take(21)->collect();
    }

    /** @param array<string, mixed> $data */
    private function reviewDuplicates(User $actor, array $data, ?int $exclude = null): void
    {
        $candidates = $this->candidates($actor, $data, $exclude)->pluck('id')->sort()->values()->all();
        $acknowledged = array_map(intval(...), $data['acknowledged_duplicate_ids'] ?? []);
        sort($acknowledged);
        if ($candidates !== $acknowledged || count($candidates) > 20) {
            throw new WriteConflict('duplicate_review_required', 'Review possible duplicates before saving. Run the duplicate check with these details, then acknowledge the matching records.');
        }
    }

    /** @param list<array{name: string, relationship: string, phone: string}> $contacts */
    private function contacts(Patient $patient, array $contacts): void
    {
        $patient->contacts()->delete();
        foreach ($contacts as $index => $contact) {
            $patient->contacts()->create($contact + ['priority' => $index + 1]);
        }
    }

    private function nameKey(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
    }

    /** @param Closure(User): Patient $operation */
    private function locked(User $actor, Closure $operation): Patient
    {
        return $this->transactions->run(function () use ($actor, $operation): Patient {
            abort_if(Permission::query()->where('code', 'staff.manage')->lockForUpdate()->first() === null, 503);
            abort_if(Permission::query()->where('code', 'patients.create')->lockForUpdate()->first() === null, 503);
            $fresh = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($fresh->is_active, 403);

            return $operation($fresh);
        });
    }
}
