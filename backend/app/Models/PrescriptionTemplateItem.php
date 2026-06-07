<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Komponen template paket obat. Meniru kolom prescription_items
 * (quantity/dose/frequency/route/duration_days) agar auto-fill ke resep lurus.
 */
class PrescriptionTemplateItem extends Model
{
    use HasUuids;

    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'prescription_template_id',
        'medication_id',
        'quantity',
        'dose',
        'frequency',
        'route',
        'duration_days',
        'sort_order',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(PrescriptionTemplate::class, 'prescription_template_id');
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }
}
