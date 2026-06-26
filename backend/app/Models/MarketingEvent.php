<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Program Marketing & Event — kegiatan + tautan Google Sheet peserta. */
class MarketingEvent extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'event_date',
        'location',
        'description',
        'participant_sheet_url',
        'participant_gid',
        'participants_synced_at',
        'is_active',
    ];

    protected $casts = [
        'event_date'             => 'date:Y-m-d',
        'participants_synced_at' => 'datetime',
        'is_active'              => 'boolean',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(MarketingEventParticipant::class, 'event_id');
    }
}
