<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SatusehatResourceLog extends Model
{
    use HasUuids;

    protected $table     = 'satusehat_resource_logs';
    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'satusehat_sync_log_id',
        'visit_id',
        'resource_type',
        'fhir_payload',
        'response_payload',
        'http_status',
        'status',
        'error_message',
        'retried_at',
    ];

    protected $casts = [
        'fhir_payload'     => 'array',
        'response_payload' => 'array',
        'retried_at'       => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function syncLog(): BelongsTo
    {
        return $this->belongsTo(SatusehatSyncLog::class, 'satusehat_sync_log_id');
    }
}
