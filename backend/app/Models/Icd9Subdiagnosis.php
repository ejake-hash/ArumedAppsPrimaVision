<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nama tindakan/prosedur klinis spesifik di bawah satu kode ICD-9-CM kanonik
 * (icd9_codes). Lihat plan ICD sub-diagnosa.
 */
class Icd9Subdiagnosis extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'icd9_code_id',
        'code',
        'name',
        'is_eye_related',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_eye_related' => 'boolean',
        'is_active'      => 'boolean',
        'sort_order'     => 'integer',
    ];

    public function icd9Code(): BelongsTo
    {
        return $this->belongsTo(Icd9Code::class, 'icd9_code_id');
    }

    public function scopeEyeRelated($query)
    {
        return $query->where('is_eye_related', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
