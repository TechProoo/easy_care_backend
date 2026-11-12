<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class ClinicalTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\ClinicalTemplateFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['is_active' => true];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ClinicalTemplateVersion::class)->orderBy('version');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
