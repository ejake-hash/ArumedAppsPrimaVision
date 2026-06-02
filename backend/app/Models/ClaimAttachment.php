<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lampiran berkas klaim BPJS (PDF/gambar hasil scan): resume RJ, hasil penunjang,
 * dll. yang belum dihasilkan aplikasi secara digital. Lihat migration
 * 2026_06_12_000002_create_claim_attachments_table.
 */
class ClaimAttachment extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const CATEGORIES = ['RESUME', 'PENUNJANG', 'SEP', 'SURAT', 'LAINNYA'];

    protected $fillable = [
        'bpjs_claim_id',
        'category',
        'title',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'uploaded_by_id',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(BpjsClaim::class, 'bpjs_claim_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by_id');
    }
}
