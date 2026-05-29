<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Addendum untuk koreksi dokumen post-FINALIZED.
 * Field utama: alasan + isi_koreksi (text). Finalisasi via signature_id terpisah.
 */
class DocumentAddendum extends Model
{
    use HasUuids;

    protected $table = 'document_addenda';   // Latin plural — bukan default 'document_addendums'
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'patient_document_id',
        'alasan',
        'isi_koreksi',
        'created_by',
        'finalized_at',
        'signature_id',
    ];

    protected $casts = [
        'finalized_at' => 'datetime',
    ];

    public function patientDocument(): BelongsTo
    {
        return $this->belongsTo(PatientDocument::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(DocumentSignature::class, 'signature_id');
    }
}
