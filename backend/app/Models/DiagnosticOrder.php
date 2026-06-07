<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiagnosticOrder extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'ordered_by_id',
        'test_type',
        'accession_number',
        'eye_side',
        'notes',
        'status',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'ordered_by_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(DiagnosticResult::class, 'diagnostic_order_id');
    }
}
