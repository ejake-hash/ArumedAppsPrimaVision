<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class DiagnosticResult extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType   = 'string';
    public $incrementing = false;

    /** URL absolut lampiran agar dokter bisa lihat gambar/PDF hasil penunjang. */
    protected $appends = ['attachment_url'];

    protected $fillable = [
        'diagnostic_order_id',
        'performed_by_id',
        'expertise_data',
        'attachment_path',
        'notes',
        'result_status',
        'uploaded_at',
        'reviewed_by_id',
        'reviewed_at',
    ];

    protected $casts = [
        'expertise_data' => 'array',
        'uploaded_at'    => 'datetime',
        'reviewed_at'    => 'datetime',
    ];

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path
            ? Storage::disk('public')->url($this->attachment_path)
            : null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(DiagnosticOrder::class, 'diagnostic_order_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'performed_by_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by_id');
    }
}
