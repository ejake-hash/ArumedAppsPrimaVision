<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 8C — hasil/dokumen eksternal (lab/radiologi pihak ke-3) pasien rawat inap.
 * Lihat migration 2026_06_05_000010.
 */
class InpatientDocument extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const CATEGORIES = ['LAB', 'RADIOLOGI', 'EKG', 'LAINNYA'];

    protected $fillable = [
        'visit_id',
        'category',
        'title',
        'file_path',
        'file_name',
        'mime_type',
        'uploaded_by_id',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by_id');
    }
}
