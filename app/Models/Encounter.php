<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
