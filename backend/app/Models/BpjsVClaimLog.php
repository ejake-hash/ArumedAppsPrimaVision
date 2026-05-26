<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BpjsVClaimLog extends Model
{
    use HasUuids;

    protected $table     = 'bpjs_vclaim_logs';
    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'action',
        'request_payload',
        'response_payload',
        'http_status',
        'is_success',
        'error_message',
    ];

    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
        'is_success'       => 'boolean',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
