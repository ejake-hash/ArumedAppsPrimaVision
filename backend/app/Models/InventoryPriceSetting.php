<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InventoryPriceSetting extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['ppn_rate'];

    protected $casts = [
        'ppn_rate' => 'decimal:2',
    ];

    public static function current(): self
    {
        return self::query()->orderBy('created_at')->firstOrFail();
    }
}
