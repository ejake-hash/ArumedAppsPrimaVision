<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecordVersion extends Model
{
    use HasUuids;

    protected $table     = 'medical_records_versions';
    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'medical_record_id',
        'version',
        'form_data',
        'changed_by_id',
        'change_reason',
    ];

    protected $casts = [
        'form_data' => 'array',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by_id');
    }
}
