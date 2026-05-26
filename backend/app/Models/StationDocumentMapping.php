<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StationDocumentMapping extends Model
{
    use HasUuids, SoftDeletes;

    protected $table     = 'station_document_mappings';
    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'station',
        'document_type_id',
        'is_available',
        'can_create',
        'can_submit',
        'can_print',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'can_create'   => 'boolean',
        'can_submit'   => 'boolean',
        'can_print'    => 'boolean',
    ];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}
