<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uang muka / deposit rawat inap (lihat migrasi create_visit_deposits_table).
 * HELD  = diterima, belum diaplikasikan ke invoice.
 * APPLIED = sudah dikredit ke invoice saat discharge (consolidateBilling).
 * REFUNDED = dikembalikan (kelebihan / pembatalan).
 */
class VisitDeposit extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_HELD     = 'HELD';
    public const STATUS_APPLIED  = 'APPLIED';
    public const STATUS_REFUNDED = 'REFUNDED';

    protected $fillable = [
        'visit_id', 'amount', 'payment_method', 'status', 'receipt_number',
        'cashier_id', 'applied_invoice_id', 'refunded_amount', 'notes',
        'received_at', 'applied_at',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'received_at'     => 'datetime',
        'applied_at'      => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cashier_id');
    }
}
