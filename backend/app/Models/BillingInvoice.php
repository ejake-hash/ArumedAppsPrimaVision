<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingInvoice extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'invoice_number',
        'subtotal',
        'discount',
        'discount_percent',
        'tax',
        'total',
        'status',
        'payment_method',
        'paid_amount',
        'cash_received',
        'paid_at',
        'covered_amount',
        'covered_by',
        'covered_at',
        'cashier_id',
        'notes',
        // Status pengiriman kwitansi ke email pasien (alternatif cetak).
        'receipt_email',
        'receipt_email_status',   // QUEUED | SENT | FAILED | null
        'receipt_email_at',
        'receipt_email_error',
    ];

    protected $casts = [
        'subtotal'         => 'decimal:2',
        'discount'         => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'tax'              => 'decimal:2',
        'total'            => 'decimal:2',
        'paid_amount'      => 'decimal:2',
        'cash_received'    => 'decimal:2',
        'covered_amount'   => 'decimal:2',
        'paid_at'          => 'datetime',
        'covered_at'       => 'datetime',
        'receipt_email_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cashier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingItem::class);
    }

    /** Porsi tanggungan per penjamin (COB). Kosong untuk invoice non-COB. */
    public function coverages(): HasMany
    {
        return $this->hasMany(BillingInvoiceCoverage::class)->orderBy('sequence');
    }
}
