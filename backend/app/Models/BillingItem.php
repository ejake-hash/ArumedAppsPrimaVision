<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'billing_invoice_id',
        'item_type',
        'category',
        'reference_id',
        'description',
        'quantity',
        'unit_price',
        'total_price',
        'discount_amount',
        'discount_percent',
        'net_price',
        // Marker DERIVED (di-set ulang builder tiap rebuild): is_absorbable = baris
        // BOLEH dikeluarkan dari paket; is_absorbed = is_absorbable && !paket_excluded.
        'is_absorbable',
        'is_absorbed',
        // OTORITATIF (model opt-out): false = baris terserap ke harga paket (default),
        // true = dikeluarkan kasir → ditagih ekstra di atas harga paket. Sumber kebenaran
        // keputusan serap per-baris (gantikan flag opt-in di tabel sumber).
        'paket_excluded',
        'notes',
    ];

    protected $casts = [
        'unit_price'       => 'decimal:2',
        'total_price'      => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'net_price'        => 'decimal:2',
        'is_absorbable'    => 'boolean',
        'is_absorbed'      => 'boolean',
        'paket_excluded'   => 'boolean',
    ];

    public function billingInvoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class);
    }
}
