<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IolRecommendation extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'diagnostic_result_id',
        'iol_item_id',
        'eye_side',
        'recommended_power',
        'formula',
        'a_constant',
        'target_refraction',
        'predicted_refraction',
        'iol_type',
        'brand',
        'notes',
        'is_approved',
        'approved_by_id',
        'approved_at',
        'is_final',
        'decided_by_id',
        'decided_at',
    ];

    protected $casts = [
        'recommended_power'    => 'decimal:2',
        'a_constant'           => 'decimal:3',
        'target_refraction'    => 'decimal:2',
        'predicted_refraction' => 'decimal:3',
        'is_approved'          => 'boolean',
        'approved_at'          => 'datetime',
        'is_final'             => 'boolean',
        'decided_at'           => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'decided_by_id');
    }

    public function iolItem(): BelongsTo
    {
        return $this->belongsTo(IolItem::class);
    }

    public function diagnosticResult(): BelongsTo
    {
        return $this->belongsTo(DiagnosticResult::class);
    }
}
