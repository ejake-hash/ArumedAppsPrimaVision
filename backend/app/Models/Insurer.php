<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Insurer extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'type',
        'parent_id',
        'code',
        'address',
        'phone',
        'email',
        'is_active',
        'is_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** True jika insurer ini adalah child TPA (inherits parent tariff). */
    public function isChildTpa(): bool
    {
        return $this->parent_id !== null;
    }

    /** ID insurer untuk lookup tarif (parent_id kalau child, id sendiri kalau tidak). */
    public function tariffInsurerId(): string
    {
        return $this->parent_id ?? $this->id;
    }

    public function procedureTariffs(): HasMany
    {
        return $this->hasMany(ProcedureTariff::class);
    }

    public function medicationTariffs(): HasMany
    {
        return $this->hasMany(MedicationTariff::class);
    }

    public function bhpTariffs(): HasMany
    {
        return $this->hasMany(BhpTariff::class);
    }

    public function iolTariffs(): HasMany
    {
        return $this->hasMany(IolTariff::class);
    }
}
