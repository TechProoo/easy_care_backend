<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class Diagnosis extends Model
{
    /** @use HasFactory<\Database\Factories\DiagnosisFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $primaryKey = 'clinical_record_id';

    public $incrementing = false;

    public $timestamps = false;

    public function record(): BelongsTo
    {
        return $this->belongsTo(ClinicalRecord::class, 'clinical_record_id');
    }
}
