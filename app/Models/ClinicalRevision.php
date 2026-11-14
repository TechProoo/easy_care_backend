<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class ClinicalRevision extends Model
{
    /** @use HasFactory<\Database\Factories\ClinicalRevisionFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function record(): BelongsTo
    {
        return $this->belongsTo(ClinicalRecord::class, 'clinical_record_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array', 'recorded_at' => 'immutable_datetime'];
    }
}
