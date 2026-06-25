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

    /** Peta kategori lokal → file_class E-Klaim (method file_upload, K2). */
    public const FILE_CLASS_MAP = [
        'RESUME'    => 'resume_medis',
        'PENUNJANG' => 'penunjang_lain',
        'SEP'       => 'lain_lain',
        'SURAT'     => 'lain_lain',
        'LAINNYA'   => 'lain_lain',
    ];

    protected $fillable = [
        'bpjs_claim_id',
        'category',
        'file_class',
        'title',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'dc_upload_status',
        'dc_upload_response',
        'dc_uploaded_at',
        'uploaded_by_id',
    ];

    protected $casts = [
        'dc_upload_status' => 'boolean',
        'dc_uploaded_at'   => 'datetime',
    ];

    /** file_class E-Klaim untuk lampiran ini (override kolom → fallback peta kategori). */
    public function resolveFileClass(): string
    {
        return $this->file_class
            ?: (self::FILE_CLASS_MAP[$this->category] ?? 'lain_lain');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(BpjsClaim::class, 'bpjs_claim_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by_id');
    }
}
