<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InacbgsGroupingLog extends Model
{
    use HasUuids;

    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'bpjs_claim_id',
        'grouper_version',
        'input_diagnosis',
        'input_tindakan',
        'cbg_code',
        'cbg_tarif',
        'severity_level',
        'engine_type',
        'status',
        'error_message',
    ];

    protected $casts = [
        'input_diagnosis' => 'array',
        'input_tindakan'  => 'array',
        'cbg_tarif'       => 'decimal:2',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function bpjsClaim(): BelongsTo
    {
        return $this->belongsTo(BpjsClaim::class);
    }
}
