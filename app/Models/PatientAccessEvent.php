<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientAccessEvent extends Model
{
    /** @use HasFactory<\Database\Factories\PatientAccessEventFactory> */
    use HasFactory;

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
