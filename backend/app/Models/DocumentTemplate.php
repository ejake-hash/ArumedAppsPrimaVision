<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplate extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const KIND_INPUT  = 'INPUT';
    public const KIND_OUTPUT = 'OUTPUT';
    public const KIND_HYBRID = 'HYBRID';

    public const COMPLEXITY_SIMPLE_BINDING    = 'SIMPLE_BINDING';
    public const COMPLEXITY_SCORED_FORM       = 'SCORED_FORM';
    public const COMPLEXITY_CUSTOM_COMPONENT  = 'CUSTOM_COMPONENT';

    protected $fillable = [
        'document_type_id',
        'name',
        'code',
        'kind',
        'complexity_kind',
        'custom_component_name',
        'source_file_path',
        'header_html',
        'body_html',
        'footer_html',
        'layout_html',
        'field_schema',
        'station_assignments',
        'page_size',
        'orientation',
        'version',
        'is_active',
        'code_locked_at',
        'deprecated_at',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'field_schema'        => 'array',
        'station_assignments' => 'array',
        'code_locked_at'      => 'datetime',
        'deprecated_at'       => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function isLocked(): bool
    {
        return $this->code_locked_at !== null;
    }

    /**
     * Aktifkan template + one-way ratchet lock kode jika belum locked.
     */
    public function activate(): void
    {
        $this->is_active = true;
        if ($this->code_locked_at === null) {
            $this->code_locked_at = now();
        }
        $this->save();
    }

    public function deactivate(): void
    {
        $this->is_active = false;
        $this->save();
    }
}
