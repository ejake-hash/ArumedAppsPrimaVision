<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Komponen snapshot paket pasien. Hanya PROCEDURE/BHP/IOL (tanpa MEDICATION).
 */
class VisitSurgeryPackageItem extends Model
{
    use HasUuids;

    protected $keyType   = 'string';
    public $incrementing = false;

    public const TYPE_PROCEDURE = 'PROCEDURE';
    public const TYPE_BHP       = 'BHP';
    public const TYPE_IOL       = 'IOL';

    /** Komponen yang boleh diedit operator di modul Bedah. */
    public const EDITABLE_TYPES = [self::TYPE_PROCEDURE, self::TYPE_BHP];

    /** Komponen yang masuk basis perhitungan diskon. */
    public const BILLABLE_TYPES = [self::TYPE_PROCEDURE, self::TYPE_BHP, self::TYPE_IOL];

    protected $fillable = [
        'visit_surgery_package_id',
        'item_type',
        'item_id',
        'quantity',
        'unit_price',
        'notes',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_price' => 'decimal:2',
    ];

    public function visitPackage(): BelongsTo
    {
        return $this->belongsTo(VisitSurgeryPackage::class, 'visit_surgery_package_id');
    }

    /** Resolve item terkait (Procedure/BhpItem/IolItem). */
    public function resolveItem(): ?Model
    {
        return match ($this->item_type) {
            self::TYPE_PROCEDURE => Procedure::find($this->item_id),
            self::TYPE_BHP       => BhpItem::find($this->item_id),
            self::TYPE_IOL       => IolItem::find($this->item_id),
            default              => null,
        };
    }

    public function subtotal(): float
    {
        return (float) $this->quantity * (float) $this->unit_price;
    }
}
