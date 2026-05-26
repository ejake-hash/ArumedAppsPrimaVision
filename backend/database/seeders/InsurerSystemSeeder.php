<?php

namespace Database\Seeders;

use App\Models\Insurer;
use Illuminate\Database\Seeder;

class InsurerSystemSeeder extends Seeder
{
    /**
     * Seed 3 baris insurer sistem (immutable, tidak boleh dihapus):
     * UMUM, BPJS, SOSIAL. Idempotent via updateOrCreate by code.
     * Asuransi & perusahaan (Allianz, Admedika, dll) di-seed manual via UI.
     */
    public function run(): void
    {
        $systems = [
            ['code' => 'UMUM',   'name' => 'UMUM',   'type' => 'UMUM'],
            ['code' => 'BPJS',   'name' => 'BPJS',   'type' => 'BPJS'],
            ['code' => 'SOSIAL', 'name' => 'SOSIAL', 'type' => 'SOSIAL'],
        ];

        foreach ($systems as $row) {
            Insurer::withTrashed()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name'      => $row['name'],
                    'type'      => $row['type'],
                    'is_system' => true,
                    'is_active' => true,
                    'parent_id' => null,
                ]
            );
        }
    }
}
