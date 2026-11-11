<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name'])]
class PatientCategory extends Model
{
    /** @use HasFactory<\Database\Factories\PatientCategoryFactory> */
    use HasFactory;

    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class, 'category_id');
    }
}
