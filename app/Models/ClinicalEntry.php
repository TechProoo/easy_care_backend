<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class ClinicalEntry extends Model
{
    /** @use HasFactory<\Database\Factories\ClinicalEntryFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $primaryKey = 'clinical_record_id';

    public $incrementing = false;

    public $timestamps = false;

    public function record(): BelongsTo
    {
        return $this->belongsTo(ClinicalRecord::class, 'clinical_record_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(ClinicalTemplateVersion::class, 'clinical_template_version_id');
    }

    public function relatedRecord(): BelongsTo
    {
        return $this->belongsTo(ClinicalRecord::class, 'related_record_id');
    }

    protected function casts(): array
    {
        return ['fields' => 'array'];
    }
}
