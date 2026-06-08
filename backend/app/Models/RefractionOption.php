<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * RefractionOption — master opsi dropdown/combobox RefraksionisView.
 * Lihat migration create_refraction_options_table untuk semantik mode/format.
 */
class RefractionOption extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    // Daftar kind yang dikenal (juga whitelist input admin).
    // 'visus' = Visus Awal (UCVA); 'visus_akhir' = Visus Akhir (BCVA) — daftar
    // terpisah karena nilainya berbeda; 'pinhole' terpisah (tanpa HM/LP/NLP).
    public const KINDS = ['sphere', 'cylinder', 'axis', 'keratometri', 'add', 'visus', 'visus_akhir', 'pinhole'];

    public const MODES = ['range', 'list'];
    public const FORMATS = ['plain', 'signed_diopter'];

    protected $fillable = [
        'kind',
        'label',
        'mode',
        'format',
        'min_value',
        'max_value',
        'step',
        'values',
        'is_active',
    ];

    protected $casts = [
        'min_value' => 'decimal:2',
        'max_value' => 'decimal:2',
        'step'      => 'decimal:2',
        'values'    => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Hasilkan daftar opsi siap-pakai untuk frontend (array of string).
     * RANGE → generate dari min..max step (format label sesuai `format`).
     * LIST  → kembalikan `values` apa adanya.
     */
    public function generateOptions(): array
    {
        if ($this->mode === 'list') {
            return array_values(array_filter(
                array_map('strval', $this->values ?? []),
                fn ($v) => $v !== ''
            ));
        }

        // mode === 'range'
        $min  = (float) $this->min_value;
        $max  = (float) $this->max_value;
        $step = (float) $this->step;

        if ($step <= 0 || $max < $min) {
            return [];
        }

        $out = [];
        // Pakai integer counter agar bebas galat akumulasi float.
        $count = (int) round(($max - $min) / $step);
        for ($i = 0; $i <= $count; $i++) {
            $val = $min + $i * $step;
            $out[] = $this->formatValue($val);
        }

        return $out;
    }

    private function formatValue(float $val): string
    {
        // Buang nol desimal berlebih: 90.00 → "90", 1.50 → "1.50" (dioptri 2 desimal).
        $isDiopter = $this->format === 'signed_diopter';

        if ($isDiopter) {
            $s = number_format($val, 2, '.', '');
            return ($val > 0 ? '+' : '') . $s; // negatif sudah bawa tanda −
        }

        // plain: integer kalau bulat, else trim trailing zero.
        if (floor($val) === $val) {
            return (string) (int) $val;
        }

        return rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.');
    }
}
