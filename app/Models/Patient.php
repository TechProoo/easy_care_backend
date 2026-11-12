<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['first_name', 'last_name', 'middle_name', 'sex', 'date_of_birth', 'dob_estimated',
    'email', 'phone', 'marital_status', 'religion', 'occupation', 'state_of_origin', 'address'])]
class Patient extends Model
{
    /** @use HasFactory<\Database\Factories\PatientFactory> */
    use HasFactory;

    protected $attributes = [
        'dob_estimated' => false,
        'life_status' => 'unknown',
        'access_status' => 'active',
        'version' => 1,
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PatientCategory::class, 'category_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $query->where('patients.access_status', 'active')
            ->when(! $viewer->hasPermission('patients.view'), fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(PatientIdentifier::class);
    }

    public function currentIdentifier(): HasOne
    {
        return $this->hasOne(PatientIdentifier::class)->whereNull('retired_at')->where('kind', 'mrn');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PatientContact::class)->orderBy('priority');
    }

    public function flags(): HasMany
    {
        return $this->hasMany(PatientFlag::class);
    }

    public function categoryHistory(): HasMany
    {
        return $this->hasMany(PatientCategoryHistory::class);
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(PatientAccessEvent::class);
    }

    public function mergeRequests(): HasMany
    {
        return $this->hasMany(PatientMergeRequest::class, 'source_patient_id');
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(PatientRelationship::class);
    }

    public function incomingRelationships(): HasMany
    {
        return $this->hasMany(PatientRelationship::class, 'related_patient_id');
    }

    protected function mrn(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => strtoupper(trim($value)));
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date:Y-m-d',
            'dob_estimated' => 'boolean',
            'deceased_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
