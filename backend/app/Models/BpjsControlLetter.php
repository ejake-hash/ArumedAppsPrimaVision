<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BpjsControlLetter extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'no_surat_kontrol',
        'tanggal_rencana_kontrol',
        'tgl_expired',
        'faskes_kontrol_kode',
        'kode_spesialis',
        'is_notified_expired',
        'status',
        'vclaim_response',
    ];

    protected $casts = [
        'tanggal_rencana_kontrol' => 'date',
        'tgl_expired'             => 'date',
        'is_notified_expired'     => 'boolean',
        'vclaim_response'         => 'array',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
