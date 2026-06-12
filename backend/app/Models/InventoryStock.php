<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryStock extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_MEDICATION = 'MEDICATION';
    public const TYPE_BHP        = 'BHP';
    public const TYPE_IOL        = 'IOL';

    // Lokasi stok. INVENTORI = gudang induk (default), sisanya = depo stok unit klinis.
    // Setiap stasiun yang boleh amprah/retur (UnitRequest::STATIONS) butuh lokasi depo
    // di sini — deliver()/receive() menolak destinasi di luar LOCATIONS.
    public const LOC_INVENTORI    = 'INVENTORI';
    public const LOC_BEDAH        = 'BEDAH';
    public const LOC_FARMASI      = 'FARMASI';
    public const LOC_RANAP        = 'RANAP';
    public const LOC_TRIASE       = 'TRIASE';
    public const LOC_REFRAKSIONIS = 'REFRAKSIONIS';
    public const LOC_IGD          = 'IGD';
    public const LOC_ADMISI       = 'ADMISI';
    public const LOCATIONS        = [
        self::LOC_INVENTORI, self::LOC_BEDAH, self::LOC_FARMASI, self::LOC_RANAP,
        self::LOC_TRIASE, self::LOC_REFRAKSIONIS, self::LOC_IGD, self::LOC_ADMISI,
    ];

    protected $fillable = [
        'item_type',
        'location',
        'item_id',
        'batch_no',
        'expiry_date',
        'qty_on_hand',
        'last_received_at',
    ];

    protected $casts = [
        'expiry_date'      => 'date',
        'qty_on_hand'      => 'decimal:2',
        'last_received_at' => 'datetime',
    ];
}
