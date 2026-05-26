<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimAuditLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'bpjs_claim_id',
        'performed_by_id',
        'action',
        'old_status',
        'new_status',
        'notes',
    ];

    public function bpjsClaim(): BelongsTo
    {
        return $this->belongsTo(BpjsClaim::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'performed_by_id');
    }
}
