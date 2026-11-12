<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['visit_type', 'care_setting'])]
class Encounter extends Model
{
    /** @use HasFactory<\Database\Factories\EncounterFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const TRANSITIONS = [
        'open' => ['in_progress', 'cancelled'],
        'in_progress' => ['closed'],
        'closed' => [], 'cancelled' => [],
    ];

    protected $attributes = ['status' => 'open', 'version' => 1];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PatientCategory::class, 'category_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DoctorAssignment::class)->orderBy('assigned_at')->orderBy('id');
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(DoctorAssignment::class)->whereNull('ended_at');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(EncounterRevision::class)->orderBy('version');
    }

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if (! $viewer->is_active || ! $viewer->hasPermission('encounters.view')) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereHas('patient', fn (Builder $patients): Builder => $patients->visibleTo($viewer));
        if (! $viewer->hasPermission('encounters.view_any')) {
            $query->whereHas('assignments', fn (Builder $assignments): Builder => $assignments->where('doctor_id', $viewer->id)
                ->where(fn (Builder $state): Builder => $state->whereNull('ended_at')->orWhere('status', 'completed')));
        }

        return $query;
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function clinicalRecords(): HasMany
    {
        return $this->hasMany(ClinicalRecord::class)->orderBy('recorded_at')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
