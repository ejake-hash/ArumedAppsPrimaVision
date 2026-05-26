<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SatusehatSyncLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'sync_date',
        'sync_type',
        'status',
        'total_sent',
        'total_failed',
        'retry_count',
        'next_retry_at',
        'notes',
    ];

    protected $casts = [
        'sync_date'     => 'date',
        'next_retry_at' => 'datetime',
    ];
}
