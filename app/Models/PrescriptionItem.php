<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class PrescriptionItem extends Model
{
    /** @use HasFactory<\Database\Factories\PrescriptionItemFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public $timestamps = false;

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id');
    }

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'duration_value' => 'decimal:3', 'quantity' => 'decimal:3'];
    }
}
