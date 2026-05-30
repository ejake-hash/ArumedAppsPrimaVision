<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BpjsReferralOut extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'bpjs_referrals_out';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'no_rujukan',
        'faskes_tujuan_kode',
        'faskes_tujuan_nama',
        'kode_spesialis',
        'poli_rujukan',
        'poli_rujukan_nama',
        'tipe_rujukan',
        'jns_pelayanan',
        'tgl_rujukan',
        'urgency',
        'diagnosa_rujukan',
        'diagnosa_nama',
        'catatan_rujukan',
        'tgl_expired',
        'status',
        'vclaim_response',
    ];

    protected $casts = [
        'tgl_rujukan'     => 'date',
        'tgl_expired'     => 'date',
        'vclaim_response' => 'array',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
