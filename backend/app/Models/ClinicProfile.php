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
        'receipt_print_settings',
        'operating_rooms',
    ];

    protected $casts = [
        'watermark_enabled'      => 'boolean',
        'receipt_print_settings' => 'array',
        'operating_rooms'        => 'array',
    ];

    /**
     * Default toggle elemen cetak kwitansi/rincian kasir. Dipakai bila kolom
     * `receipt_print_settings` masih null (klinik belum pernah set).
     */
    public const RECEIPT_PRINT_DEFAULTS = [
        'show_logo'      => true,
        'show_stamp'     => true,
        'show_esign'     => true,
        'show_footer'    => true,
        'show_watermark' => true,
    ];

    /** Setting cetak final (default ditimpa nilai tersimpan). */
    public function receiptPrintSettings(): array
    {
        return array_merge(self::RECEIPT_PRINT_DEFAULTS, $this->receipt_print_settings ?? []);
    }
}
