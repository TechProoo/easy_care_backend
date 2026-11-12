<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class AppointmentFollowUp extends Model
{
    /** @use HasFactory<\Database\Factories\AppointmentFollowUpFactory> */
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'appointment_id';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function previousAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'previous_appointment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
