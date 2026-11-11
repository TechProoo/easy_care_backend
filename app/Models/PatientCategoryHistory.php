<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientCategoryHistory extends Model
{
    /** @use HasFactory<\Database\Factories\PatientCategoryHistoryFactory> */
    use HasFactory;

    protected $table = 'patient_category_history';

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function previousCategory(): BelongsTo
    {
        return $this->belongsTo(PatientCategory::class, 'previous_category_id');
    }

    public function newCategory(): BelongsTo
    {
        return $this->belongsTo(PatientCategory::class, 'new_category_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }
}
