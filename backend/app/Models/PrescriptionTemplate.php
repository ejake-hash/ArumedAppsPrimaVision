<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Paket Obat Pasca-Bedah (template resep rutin). Dipilih dokter di Tab Pasca-Bedah
 * untuk auto-isi daftar obat pasca-operasi. Global (dipakai bersama semua dokter).
 */
class PrescriptionTemplate extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'category',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionTemplateItem::class)->orderBy('sort_order');
    }
}
