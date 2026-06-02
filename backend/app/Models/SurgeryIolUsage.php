<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurgeryIolUsage extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'surgery_iol_usage';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'surgery_record_id',
        'iol_item_id',
        'eye_side',
        'brand',
        'model',
        'power',
        'lot_number',
        'serial_number',
        'gtin',
        'gs1_barcode',
        'expiry_date',
        'stock_consumed',
    ];

    protected $casts = [
        'power'          => 'decimal:2',
        'expiry_date'    => 'date',
        'stock_consumed' => 'boolean',
    ];

    public function surgeryRecord(): BelongsTo
    {
        return $this->belongsTo(SurgeryRecord::class);
    }

    public function iolItem(): BelongsTo
    {
        return $this->belongsTo(IolItem::class);
    }
}
