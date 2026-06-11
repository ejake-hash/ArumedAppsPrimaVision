<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Varian kemasan jual obat (per Strip / Box, dst.) dengan harga INDEPENDEN per
 * kemasan. Kemasan dasar (satuan kecil, isi=1) TIDAK disimpan di sini — tetap
 * medication_tariffs.price. insurer_id NULL = berlaku semua penjamin; baris
 * ber-insurer = override (resolusi: insurer persis → NULL, label sama).
 * isi = jumlah satuan kecil per kemasan; invarian resep:
 * prescription_items.quantity = sale_unit_qty × isi (stok tetap satuan kecil).
 */
class MedicationSaleUnit extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = ['medication_id', 'insurer_id', 'label', 'isi', 'price', 'is_active'];

    protected $casts = ['isi' => 'integer', 'price' => 'decimal:2', 'is_active' => 'boolean'];

    public function medication(): BelongsTo { return $this->belongsTo(Medication::class); }
    public function insurer(): BelongsTo    { return $this->belongsTo(Insurer::class); }
}
