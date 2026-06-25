<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BpjsRmLog extends Model
{
    use HasUuids;

    protected $table     = 'bpjs_rm_logs';
    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'no_sep',
        'action',
        'fhir_payload',
        'response_payload',
        'http_status',
        'status',
        'error_message',
    ];

    protected $casts = [
        'fhir_payload'     => 'array',
        'response_payload' => 'array',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
