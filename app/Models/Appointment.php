<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['first_name', 'last_name', 'phone', 'starts_at', 'ends_at', 'booking_channel',
    'visit_type', 'specialist_name', 'specialist_email'])]
class Appointment extends Model
{
    /** @use HasFactory<\Database\Factories\AppointmentFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const TRANSITIONS = [
        'booked' => ['confirmed', 'attended', 'cancelled', 'no_show'],
        'confirmed' => ['attended', 'cancelled', 'no_show'],
        'attended' => [], 'cancelled' => [], 'no_show' => [],
    ];

    protected $attributes = ['status' => 'booked', 'version' => 1];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function encounter(): HasOne
    {
        return $this->hasOne(Encounter::class);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
