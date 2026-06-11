<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurgeryPackageItem extends Model
{
    use HasUuids;

    protected $keyType   = 'string';
    public $incrementing = false;

    public const TYPE_PROCEDURE  = 'PROCEDURE';
    public const TYPE_MEDICATION = 'MEDICATION';
    public const TYPE_BHP        = 'BHP';
    public const TYPE_IOL        = 'IOL';

    public const TYPES = [
        self::TYPE_PROCEDURE,
        self::TYPE_MEDICATION,
        self::TYPE_BHP,
        self::TYPE_IOL,
    ];

    protected $fillable = [
        'surgery_package_id',
        'item_type',
        'item_id',
        'quantity',
        'default_price',
        'notes',
    ];

    protected $casts = [
        'quantity'      => 'integer',
        'default_price' => 'decimal:2',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(SurgeryPackage::class, 'surgery_package_id');
    }

    /**
     * Resolve item terkait (Procedure/Medication/BhpItem/IolItem) berdasarkan item_type.
     * Dipakai untuk eager-load fleksibel di service layer.
     */
    public function resolveItem(): ?Model
    {
        return match ($this->item_type) {
            self::TYPE_PROCEDURE  => Procedure::find($this->item_id),
            self::TYPE_MEDICATION => Medication::find($this->item_id),
            self::TYPE_BHP        => BhpItem::find($this->item_id),
            self::TYPE_IOL        => IolItem::find($this->item_id),
            default               => null,
        };
    }

    /**
     * Seperti resolveItem() tapi ikut master yang sudah soft-deleted — KHUSUS jalur
     * tampilan/export agar item lama tetap bernama (bukan "-"). Jalur billing/lookup
     * tetap pakai resolveItem().
     */
    public function resolveItemWithTrashed(): ?Model
    {
        return match ($this->item_type) {
            self::TYPE_PROCEDURE  => Procedure::withTrashed()->find($this->item_id),
            self::TYPE_MEDICATION => Medication::withTrashed()->find($this->item_id),
            self::TYPE_BHP        => BhpItem::withTrashed()->find($this->item_id),
            self::TYPE_IOL        => IolItem::withTrashed()->find($this->item_id),
            default               => null,
        };
    }

    public function subtotal(): float
    {
        return (float) $this->quantity * (float) $this->default_price;
    }
}
