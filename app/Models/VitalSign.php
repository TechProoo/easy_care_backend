<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class VitalSign extends Model
{
    /** @use HasFactory<\Database\Factories\VitalSignFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $primaryKey = 'clinical_record_id';

    public $incrementing = false;

    public $timestamps = false;

    public function bodyMassIndex(): ?float
    {
        if ($this->weight_kg === null || $this->height_m === null || (float) $this->height_m <= 0) {
            return null;
        }

        return round((float) $this->weight_kg / ((float) $this->height_m ** 2), 2);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(ClinicalRecord::class, 'clinical_record_id');
    }

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime', 'temperature_c' => 'decimal:2',
            'systolic_mmhg' => 'integer', 'diastolic_mmhg' => 'integer',
            'weight_kg' => 'decimal:3', 'height_m' => 'decimal:3', 'blood_glucose' => 'decimal:3',
            'pulse_bpm' => 'decimal:2', 'respiratory_rate' => 'decimal:2',
            'spo2_percent' => 'decimal:2', 'fetal_heart_rate' => 'decimal:2',
        ];
    }
}
