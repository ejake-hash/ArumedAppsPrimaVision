<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pemakaian BHP yang diinput DOKTER pada kunjungan (paralel VisitService untuk
 * tindakan). Dibaca KasirService::buildBhpLines agar tertagih & tahan rebuild.
 */
class VisitBhpUsage extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'bhp_item_id',
        'performed_by_id',
        'quantity',
        'unit_price',
        'consumed_batches',
        'notes',
        'verified_at',
        'verified_by_id',
    ];

    protected $casts = [
        'quantity'         => 'integer',
        'unit_price'       => 'decimal:2',
        'consumed_batches' => 'array',
        'verified_at'      => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function bhpItem(): BelongsTo
    {
        return $this->belongsTo(BhpItem::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by_id');
    }
}
