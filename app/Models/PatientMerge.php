<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientMerge extends Model
{
    /** @use HasFactory<\Database\Factories\PatientMergeFactory> */
    use HasFactory;

    public function source(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'source_patient_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'target_patient_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PatientMergeRequest::class, 'request_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return ['approved_at' => 'immutable_datetime', 'source_version' => 'integer', 'target_version' => 'integer'];
    }
}
