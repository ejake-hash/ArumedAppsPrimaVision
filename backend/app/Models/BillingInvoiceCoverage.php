<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Porsi tanggungan satu penjamin atas satu invoice (COB).
 * sequence 1 = penjamin-1, 2 = penjamin-2. Lihat migrasi billing_invoice_coverages.
 */
class BillingInvoiceCoverage extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'billing_invoice_id',
        'insurer_id',
        'guarantor_type',
        'sequence',
        'covered_amount',
        'basis_amount',
        'verification_id',
        'notes',
    ];

    protected $casts = [
        'sequence'       => 'integer',
        'covered_amount' => 'decimal:2',
        'basis_amount'   => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(InsuranceVerification::class, 'verification_id');
    }
}
