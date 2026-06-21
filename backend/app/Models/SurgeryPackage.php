<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurgeryPackage extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_BEDAH = 'BEDAH';
    public const TYPE_PEMERIKSAAN = 'PEMERIKSAAN';

    /**
     * Tipe operasi (klasifikasi klinis) — PENENTU form resmi mana yang terbit
     * (VITREORETINA → RM 10.1, KATARAK → RM 2.3, dst). Berbeda dari `category`
     * yang free-text untuk label/filter tarif.
     */
    public const SURGERY_TYPES = ['KATARAK', 'VITREORETINA', 'GLAUKOMA', 'LAINNYA'];

    protected $fillable = [
        'legacy_uuid',
        'name',
        'code',
        'package_type',
        'category',
        'surgery_type',
        'description',
        'keterangan',
        'estimated_duration',
        'price',
        'total_base_price',
        'is_active',
    ];

    protected $casts = [
        'price'               => 'decimal:2',
        'total_base_price'    => 'decimal:2',
        'is_active'           => 'boolean',
    ];

    /**
     * Tebak surgery_type dari nama paket (AUTO-SARAN). Sumber kebenaran tetap
     * kolom eksplisit; ini hanya default saat admin belum memilih. VITREORETINA
     * diperiksa DULU agar kasus gabungan (phaco+vitrektomi) jatuh ke retina.
     * Selaras dgn deteksi nama di BedahView (VITREK_RE / IOL_RE).
     */
    public static function suggestSurgeryType(?string $name): ?string
    {
        $n = trim((string) $name);
        if ($n === '') {
            return null;
        }
        // INJEKSI diperiksa DULU: paket injeksi anti-VEGF sering memuat nama obat
        // intravitreal — jangan terjebak ke KATARAK/VITREORETINA.
        if (preg_match('/anti.?vegf|intravitreal|injeksi|avastin|lucentis|eylea|aflibercept|ranibizumab|bevacizumab/i', $n)) {
            return 'INJEKSI';
        }
        if (preg_match('/vitrek|vitrec|vitreous|ppv|pars plana|vitreoretina|retina|buckle|bakel/i', $n)) {
            return 'VITREORETINA';
        }
        if (preg_match('/phaco|fako|katarak|cataract|\biol\b|sics|lensa intraokular/i', $n)) {
            return 'KATARAK';
        }
        if (preg_match('/pterygium|pterigium|pterygii/i', $n)) {
            return 'PTERYGIUM';
        }
        if (preg_match('/glaukoma|glaucoma|trabekulekto|trabeculecto|trabekulo|iridekto|iridecto|ahmed|baerveldt/i', $n)) {
            return 'GLAUKOMA';
        }
        return null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function surgerySchedules(): HasMany
    {
        return $this->hasMany(SurgerySchedule::class);
    }

    public function doctorExaminations(): HasMany
    {
        return $this->hasMany(DoctorExamination::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SurgeryPackageItem::class);
    }

    public function packageTariffs(): HasMany
    {
        return $this->hasMany(SurgeryPackageTariff::class);
    }

    /**
     * Hitung ulang total_base_price dari semua items dan simpan.
     * Dipanggil setiap kali item paket ditambah/diubah/dihapus.
     */
    public function recalcTotalBasePrice(): float
    {
        $total = (float) $this->items()
            ->selectRaw('COALESCE(SUM(quantity * default_price), 0) as total')
            ->value('total');

        $this->update(['total_base_price' => $total]);

        // Tarif berbasis %-diskon mengikuti base (sell_price = base × (1 − pct/100)).
        // Tarif nominal SENGAJA tidak diubah (harga kepastian per surat edaran).
        $this->packageTariffs()->whereNotNull('discount_percent')->get()->each(function ($t) use ($total) {
            $t->update(['sell_price' => round($total * (1 - (float) $t->discount_percent / 100), 2)]);
        });

        return $total;
    }
}
