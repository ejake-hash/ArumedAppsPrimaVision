<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pemetaan poli lokal (doctor_schedules.poli_code) ↔ kode poli BPJS.
 * Sumber kebenaran kode BPJS = /referensi/poli (VClaim) atau /ref/poli (Antrean).
 */
class BpjsPoliMapping extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'poli_code',
        'poli_name',
        'bpjs_poli_code',
        'bpjs_poli_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Resolve kode poli BPJS dari poli_code lokal (null bila belum dipetakan). */
    public static function bpjsCodeFor(?string $poliCode): ?string
    {
        if (! $poliCode) {
            return null;
        }

        return static::where('poli_code', $poliCode)->where('is_active', true)->value('bpjs_poli_code');
    }

    /** Resolve poli_code lokal dari kode poli BPJS (null bila belum dipetakan). */
    public static function localCodeFor(?string $bpjsPoliCode): ?string
    {
        if (! $bpjsPoliCode) {
            return null;
        }

        return static::where('bpjs_poli_code', $bpjsPoliCode)->where('is_active', true)->value('poli_code');
    }
}
