<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryPrice extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_MEDICATION = 'MEDICATION';
    public const TYPE_BHP        = 'BHP';
    public const TYPE_IOL        = 'IOL';

    protected $fillable = [
        'item_type',
        'item_id',
        'hpp',
        'margin_percent',
        'ppn_enabled',
        'hja',
        'notes',
        'effective_date',
        'updated_by',
    ];

    protected $casts = [
        'hpp'            => 'decimal:2',
        'margin_percent' => 'decimal:2',
        'ppn_enabled'    => 'boolean',
        'hja'            => 'decimal:2',
        'effective_date' => 'date',
    ];

    public static function computeHja(float $hpp, float $marginPercent, bool $ppnEnabled, float $ppnRate): float
    {
        $base = $hpp * (1 + ($marginPercent / 100));
        if ($ppnEnabled) {
            $base = $base * (1 + ($ppnRate / 100));
        }
        return round($base, 2);
    }
}
