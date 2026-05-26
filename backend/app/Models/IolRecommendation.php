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
        'eye_side',
        'recommended_power',
        'iol_type',
        'brand',
        'notes',
        'is_approved',
        'approved_by_id',
        'approved_at',
    ];

    protected $casts = [
        'recommended_power' => 'decimal:2',
        'is_approved'       => 'boolean',
        'approved_at'       => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by_id');
    }

    public function diagnosticResult(): BelongsTo
    {
        return $this->belongsTo(DiagnosticResult::class);
    }
}
