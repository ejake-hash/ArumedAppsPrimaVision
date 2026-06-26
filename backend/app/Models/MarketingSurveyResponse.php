<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Tanggapan Survei Kepuasan (cache dari Google Form/Sheet). */
class MarketingSurveyResponse extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'submitted_at',
        'respondent_name',
        'score',
        'payload',
        'row_hash',
        'synced_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'score'        => 'integer',
        'payload'      => 'array',
        'synced_at'    => 'datetime',
    ];
}
