<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only signature record. Tidak ada updated_at; setiap upaya `update()` /
 * `delete()` di service-level harus throw ImmutableRecordException — di
 * model-level kita override $timestamps + guard di booted().
 */
class DocumentSignature extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    /** Append-only — tidak ada updated_at. */
    public $timestamps = false;

    public const SIGNER_TYPES = ['patient', 'guardian', 'witness', 'doctor', 'nurse', 'staff'];

    protected $fillable = [
        'signature_id',
        'patient_document_id',
        'signer_type',
        'signer_user_id',
        'signer_patient_id',
        'signer_external_identity',
        'signature_svg',
        'signature_png_base64',
        'captured_at',
        'captured_device_info',
        'captured_by_facilitator_user_id',
        'biometric_metadata',
        'audit_log',
        'integrity_hash',
        'created_at',
    ];

    protected $casts = [
        'signer_external_identity' => 'array',
        'captured_device_info'     => 'array',
        'biometric_metadata'       => 'array',
        'audit_log'                => 'array',
        'captured_at'              => 'datetime',
        'created_at'               => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (DocumentSignature $sig) {
            throw new RuntimeException("DocumentSignature {$sig->id} is append-only — update tidak diizinkan.");
        });
        static::deleting(function (DocumentSignature $sig) {
            throw new RuntimeException("DocumentSignature {$sig->id} is append-only — delete tidak diizinkan.");
        });
    }

    public function patientDocument(): BelongsTo
    {
        return $this->belongsTo(PatientDocument::class);
    }

    public function signerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    public function signerPatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'signer_patient_id');
    }

    public function capturedByFacilitator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_facilitator_user_id');
    }
}
