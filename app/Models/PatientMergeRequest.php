<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientMergeRequest extends Model
{
    /** @use HasFactory<\Database\Factories\PatientMergeRequestFactory> */
    use HasFactory;

    public function source(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'source_patient_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'target_patient_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected function casts(): array
    {
        return ['reviewed_at' => 'immutable_datetime', 'version' => 'integer', 'source_version' => 'integer', 'target_version' => 'integer'];
    }
}
