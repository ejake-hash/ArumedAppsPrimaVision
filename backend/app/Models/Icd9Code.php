<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Icd9Code extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'code',
        'category',
        'description',
        'indonesian_description',
        'is_eye_related',
        'is_favorite',
    ];

    protected $casts = [
        'is_eye_related' => 'boolean',
        'is_favorite'    => 'boolean',
    ];

    public function scopeEyeRelated($query)
    {
        return $query->where('is_eye_related', true);
    }

    public function scopeFavorite($query)
    {
        return $query->where('is_favorite', true);
    }
}
