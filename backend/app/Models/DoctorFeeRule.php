<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aturan honor (jasa medis) dokter — modul Keuangan. Lihat migrasi
 * create_doctor_fee_rules. Resolusi paling-spesifik-menang di KeuanganService.
 */
class DoctorFeeRule extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_PERCENT_CATEGORY = 'PERCENT_CATEGORY';
    public const TYPE_PERCENT_PAYER    = 'PERCENT_PAYER';
    public const TYPE_NOMINAL_PACKAGE  = 'NOMINAL_PACKAGE';

    public const RULE_TYPES   = [self::TYPE_PERCENT_CATEGORY, self::TYPE_PERCENT_PAYER, self::TYPE_NOMINAL_PACKAGE];
    public const PAYER_GROUPS = ['BPJS', 'UMUM'];
    public const BASES        = ['GROSS', 'NET'];

    protected $fillable = [
        'employee_id',
        'rule_type',
        'category',
        'surgery_package_id',
        'payer_group',
        'percent',
        'nominal',
        'basis',
        'effective_from',
        'effective_to',
        'label',
        'is_active',
    ];

    protected $casts = [
        'percent'        => 'decimal:2',
        'nominal'        => 'decimal:2',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function surgeryPackage(): BelongsTo
    {
        return $this->belongsTo(SurgeryPackage::class, 'surgery_package_id');
    }
}
