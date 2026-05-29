<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Checklist dokumen wajib per TPA (Resume Medis, Surat Rujukan, Kwitansi, dll).
 * Dipakai untuk auto-populate documents_checklist saat createDraftKlaim.
 */
class InsurerDocumentRequirement extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'insurer_id',
        'document_name',
        'is_required',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }
}
