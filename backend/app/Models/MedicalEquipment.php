<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicalEquipment extends Model
{
    use HasUuids, SoftDeletes;

    // Inflector Laravel menjadikan "equipment" uncountable → infer ke
    // `medical_equipment` (tanpa s), padahal tabel fisik & FK pakai
    // `medical_equipments`. Deklarasikan eksplisit.
    protected $table = 'medical_equipments';

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_ACTIVE      = 'ACTIVE';
    public const STATUS_MAINTENANCE = 'MAINTENANCE';
    public const STATUS_RETIRED     = 'RETIRED';

    public const CATEGORY_MICROSCOPE     = 'MICROSCOPE';
    public const CATEGORY_PHACO_MACHINE  = 'PHACO_MACHINE';
    public const CATEGORY_BIOMETRY       = 'BIOMETRY';
    public const CATEGORY_AUTOREFRACTOR  = 'AUTOREFRACTOR';
    public const CATEGORY_LAINNYA        = 'LAINNYA';

    public const CATEGORIES = [
        self::CATEGORY_MICROSCOPE,
        self::CATEGORY_PHACO_MACHINE,
        self::CATEGORY_BIOMETRY,
        self::CATEGORY_AUTOREFRACTOR,
        self::CATEGORY_LAINNYA,
    ];

    protected $fillable = [
        'code', 'name', 'category', 'brand', 'model', 'serial_number',
        'location', 'status', 'calibration_due_at', 'purchase_date',
        'description', 'is_active',
    ];

    protected $casts = [
        'calibration_due_at' => 'date',
        'purchase_date'      => 'date',
        'is_active'          => 'boolean',
    ];

    public function tariffs(): HasMany
    {
        return $this->hasMany(MedicalEquipmentTariff::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(MedicalEquipmentUsage::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
