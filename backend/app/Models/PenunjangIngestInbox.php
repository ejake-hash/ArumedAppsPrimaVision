<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Hasil penunjang masuk yang belum tertaut ke order (lihat migrasi). Operator
 * menautkan manual via tab Inbox PenunjangView.
 */
class PenunjangIngestInbox extends Model
{
    use HasUuids, SoftDeletes;

    protected $table     = 'penunjang_ingest_inbox';
    protected $keyType   = 'string';
    public $incrementing = false;

    protected $appends = ['attachment_url'];

    protected $fillable = [
        'attachment_path',
        'source',
        'accession_number',
        'claimed_no_rm',
        'original_filename',
        'external_ref',
        'status',
        'assigned_order_id',
        'assigned_by_id',
        'assigned_at',
        'notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path
            ? Storage::disk('public')->url($this->attachment_path)
            : null;
    }

    public function assignedOrder(): BelongsTo
    {
        return $this->belongsTo(DiagnosticOrder::class, 'assigned_order_id');
    }
}
