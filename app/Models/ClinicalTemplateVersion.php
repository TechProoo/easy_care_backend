<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class ClinicalTemplateVersion extends Model
{
    /** @use HasFactory<\Database\Factories\ClinicalTemplateVersionFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public $timestamps = false;

    public function template(): BelongsTo
    {
        return $this->belongsTo(ClinicalTemplate::class, 'clinical_template_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ClinicalEntry::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'fields' => 'array', 'published_at' => 'immutable_datetime'];
    }
}
