<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class DoctorAssignment extends Model
{
    /** @use HasFactory<\Database\Factories\DoctorAssignmentFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const TRANSITIONS = [
        'assigned' => ['accepted', 'cancelled', 'reassigned'],
        'accepted' => ['completed', 'reassigned'],
        'completed' => [], 'cancelled' => [], 'reassigned' => [],
    ];

    protected $attributes = ['status' => 'assigned', 'version' => 1];

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
