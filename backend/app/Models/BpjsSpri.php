<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SPRI (Surat Perintah Rawat Inap) BPJS — dibuat dari modul RANAP.
 *
 * status: DRAFT (belum/ gagal terbit) | SUCCESS (no_spri terisi) | FAILED.
 * Hanya yang belum terbit (no_spri kosong) yang boleh dihapus lokal.
 */
class BpjsSpri extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'bpjs_spri';

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_DRAFT   = 'DRAFT';
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED  = 'FAILED';

    protected $fillable = [
        'visit_id',
        'no_spri',
        'tgl_rencana',
        'poli_kontrol',
        'kode_dokter',
        'status',
        'vclaim_response',
    ];

    protected $casts = [
        'tgl_rencana'     => 'date',
        'vclaim_response' => 'array',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
