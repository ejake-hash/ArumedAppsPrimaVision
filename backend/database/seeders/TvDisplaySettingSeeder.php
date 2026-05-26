<?php

namespace Database\Seeders;

use App\Models\TvDisplaySetting;
use Illuminate\Database\Seeder;

class TvDisplaySettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (TvDisplaySetting::defaults() as $station => $payload) {
            TvDisplaySetting::updateOrCreate(
                ['station' => $station],
                $payload,
            );
        }
    }
}
