<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BpjsAplicareLog extends Model
{
    use HasUuids;

    protected $table     = 'bpjs_aplicare_logs';
    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'room_id',
        'action',
        'kodekelas',
        'koderuang',
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

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
