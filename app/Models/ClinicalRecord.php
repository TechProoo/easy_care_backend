<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class ClinicalRecord extends Model
{
    /** @use HasFactory<\Database\Factories\ClinicalRecordFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['status' => 'draft', 'version' => 1];

    public function scopeFinalized(Builder $query): Builder
    {
        return $query->whereIn($query->getModel()->qualifyColumn('status'), ['signed', 'voided']);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->finalized()->whereDoesntHave('amendment', fn (Builder $amendment): Builder => $amendment->finalized());
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function amendedRecord(): BelongsTo
    {
        return $this->belongsTo(ClinicalRecord::class, 'amends_id');
    }

    public function amendment(): HasOne
    {
        return $this->hasOne(ClinicalRecord::class, 'amends_id');
    }

    public function vitals(): HasOne
    {
        return $this->hasOne(VitalSign::class);
    }

    public function entry(): HasOne
    {
        return $this->hasOne(ClinicalEntry::class);
    }

    public function diagnosis(): HasOne
    {
        return $this->hasOne(Diagnosis::class);
    }

    public function procedure(): HasOne
    {
        return $this->hasOne(ClinicalProcedure::class);
    }

    public function prescription(): HasOne
    {
        return $this->hasOne(Prescription::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'recorded_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime'];
    }
}
