<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'nip',
        'profession',
        'sip',
        'str',
        'bpjs_dpjp_code',
        'nik',
        'satusehat_ihs',
        'phone',
        'email',
        'address',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function doctorExaminations(): HasMany
    {
        return $this->hasMany(DoctorExamination::class);
    }

    public function refractionRecords(): HasMany
    {
        return $this->hasMany(RefractionRecord::class);
    }

    public function doctorSchedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }
}
