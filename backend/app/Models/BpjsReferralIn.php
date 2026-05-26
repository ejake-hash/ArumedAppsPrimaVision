<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BpjsReferralIn extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'bpjs_referrals_in';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'no_rujukan',
        'tgl_rujukan',
        'tgl_expired',
        'fktp_kode',
        'fktp_nama',
        'diagnosa_rujukan',
        'diagnosa_nama',
        'max_kunjungan',
        'sisa_kunjungan',
        'kunjungan_ke',
        'is_notified_expired',
        'status',
        'vclaim_response',
    ];

    protected $casts = [
        'tgl_rujukan'         => 'date',
        'tgl_expired'         => 'date',
        'is_notified_expired' => 'boolean',
        'vclaim_response'     => 'array',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
