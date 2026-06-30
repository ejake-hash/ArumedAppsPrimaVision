<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitCob extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'visit_cob';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'penjamin1_type',
        'penjamin1_insurer_id',
        'penjamin2_type',
        'penjamin2_insurer_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** COB aktif (punya penjamin-2) untuk satu visit, atau null. */
    public static function activeForVisit(string $visitId): ?self
    {
        return static::where('visit_id', $visitId)
            ->where('is_active', true)
            ->whereNotNull('penjamin2_insurer_id')
            ->first();
    }

    /**
     * Salin pengaturan COB ini ke visit anak (rujukan internal IGD/Dokter → poli),
     * agar tagihan anak juga di-split BPJS + penjamin-2 dan masuk Verifikasi Asuransi.
     */
    public function replicateTo(string $childVisitId): self
    {
        return static::create([
            'visit_id'             => $childVisitId,
            'penjamin1_type'       => $this->penjamin1_type,
            'penjamin1_insurer_id' => $this->penjamin1_insurer_id,
            'penjamin2_type'       => $this->penjamin2_type,
            'penjamin2_insurer_id' => $this->penjamin2_insurer_id,
            'is_active'            => true,
            'notes'                => $this->notes,
        ]);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function penjamin1(): BelongsTo
    {
        return $this->belongsTo(Insurer::class, 'penjamin1_insurer_id');
    }

    public function penjamin2(): BelongsTo
    {
        return $this->belongsTo(Insurer::class, 'penjamin2_insurer_id');
    }
}
