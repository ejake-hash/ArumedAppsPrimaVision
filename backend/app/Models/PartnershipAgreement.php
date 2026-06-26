<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Monitoring Kerjasama (PKS) dengan asuransi/perusahaan. Status (AKTIF/
 * AKAN_BERAKHIR/BERAKHIR) DIHITUNG dari pks_end_date — bukan kolom tersimpan.
 */
class PartnershipAgreement extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    /** Ambang "akan berakhir" (hari) sebelum pks_end_date. */
    public const EXPIRING_SOON_DAYS = 30;

    protected $fillable = [
        'insurer_id',
        'partner_name',
        'partner_type',
        'pks_number',
        'pks_start_date',
        'addendum_date',
        'pks_end_date',
        'notes',
        'pic_name',
        'pic_phone',
        'is_active',
    ];

    protected $casts = [
        'pks_start_date' => 'date:Y-m-d',
        'addendum_date'  => 'date:Y-m-d',
        'pks_end_date'   => 'date:Y-m-d',
        'is_active'      => 'boolean',
    ];

    protected $appends = ['status'];

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    /** AKTIF / AKAN_BERAKHIR / BERAKHIR / TANPA_AKHIR (pks_end_date null). */
    public function getStatusAttribute(): string
    {
        if (! $this->pks_end_date) {
            return 'TANPA_AKHIR';
        }

        $today = Carbon::today();
        if ($this->pks_end_date->lt($today)) {
            return 'BERAKHIR';
        }
        if ($this->pks_end_date->lte($today->copy()->addDays(self::EXPIRING_SOON_DAYS))) {
            return 'AKAN_BERAKHIR';
        }

        return 'AKTIF';
    }
}
