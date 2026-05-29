<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiagnosticTestType extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Kode khusus pemeriksaan Biometri. Dipakai sebagai penanda untuk
     * auto-generate rekomendasi IOL + form OD/OS khusus, menggantikan
     * string nama 'Biometri' yang dulu di-hardcode di berbagai tempat.
     */
    public const BIOMETRI_CODE = 'BIOM';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'code',
        'name',
        'category',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
