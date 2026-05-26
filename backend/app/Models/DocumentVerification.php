<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVerification extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'patient_document_id',
        'verification_token',
        'verification_url',
        'document_hash',
        'is_valid',
        'scan_count',
        'last_scanned_at',
    ];

    protected $casts = [
        'is_valid'        => 'boolean',
        'last_scanned_at' => 'datetime',
    ];

    public function patientDocument(): BelongsTo
    {
        return $this->belongsTo(PatientDocument::class);
    }
}
