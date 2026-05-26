<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurgeryRequestIol extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'surgery_request_iol';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'surgery_request_id',
        'eye_side',
        'requested_iol_type',
        'requested_power',
        'iol_item_id',
        'notes',
    ];

    protected $casts = [
        'requested_power' => 'decimal:2',
    ];

    public function surgeryRequest(): BelongsTo
    {
        return $this->belongsTo(SurgeryRequest::class);
    }

    public function iolItem(): BelongsTo
    {
        return $this->belongsTo(IolItem::class);
    }
}
