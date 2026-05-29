<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ClinicProfile extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'clinic_name',
        'clinic_code',
        'address',
        'phone',
        'email',
        'logo_path',
        'signature_path',
        'stamp_path',
        'director_name',
        'director_sip',
        'rm_format',
        'rm_seq_length',
        'rm_last_seq',
        'pdf_engine',
        'watermark_enabled',
        'watermark_type',
        'operating_rooms',
    ];

    protected $casts = [
        'watermark_enabled' => 'boolean',
        'operating_rooms'   => 'array',
    ];
}
