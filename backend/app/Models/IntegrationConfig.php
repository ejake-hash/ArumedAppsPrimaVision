<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class IntegrationConfig extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'system_name',
        'is_enabled',
        'base_url',
        'credentials',
        'configuration',
        'last_test_status',
        'last_tested_at',
        'notes',
    ];

    protected $casts = [
        'is_enabled'    => 'boolean',
        'credentials'   => 'array',
        'configuration' => 'array',
        'last_tested_at' => 'datetime',
    ];
}
