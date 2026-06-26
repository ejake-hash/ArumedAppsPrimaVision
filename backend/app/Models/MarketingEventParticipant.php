<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Peserta kegiatan marketing (snapshot baris Google Sheet). */
class MarketingEventParticipant extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'name',
        'phone',
        'payload',
        'row_hash',
        'synced_at',
    ];

    protected $casts = [
        'payload'   => 'array',
        'synced_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(MarketingEvent::class, 'event_id');
    }
}
