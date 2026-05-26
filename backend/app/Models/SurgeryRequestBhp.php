<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurgeryRequestBhp extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'surgery_request_bhp';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'surgery_request_id',
        'bhp_item_id',
        'quantity',
        'notes',
    ];

    public function surgeryRequest(): BelongsTo
    {
        return $this->belongsTo(SurgeryRequest::class);
    }

    public function bhpItem(): BelongsTo
    {
        return $this->belongsTo(BhpItem::class);
    }
}
