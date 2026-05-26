<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StationMappingSeeder extends Seeder
{
    public function run(): void
    {
        // Helper: resolve document_type_id by code
        $id = fn(string $code) => DocumentType::where('code', $code)->value('id');

        // [station, code, can_create, can_submit, can_print]
        $mappings = [
            // ADMISI
            ['ADMISI', 'RM-1.1', true,  true,  true],
            ['ADMISI', 'RM-1.2', true,  true,  true],
            ['ADMISI', 'RM-1.3', true,  true,  true],
            ['ADMISI', 'RM-7.1', true,  true,  true],

            // TRIASE
            ['TRIASE', 'RM-2.1', true,  true,  true],

            // REFRAKSIONIS
            ['REFRAKSIONIS', 'RM-2.2', true, true, true],
            ['REFRAKSIONIS', 'RM-4.2', true, true, true],

            // DOKTER
            ['DOKTER', 'RM-2.3', true, true,  true],
            ['DOKTER', 'RM-3.1', true, true,  false],
            ['DOKTER', 'RM-4.1', true, true,  false],
            ['DOKTER', 'RM-5.1', true, true,  true],
            ['DOKTER', 'RM-6.1', true, true,  true],
            ['DOKTER', 'RM-6.2', true, true,  true],
            ['DOKTER', 'RM-6.3', true, true,  true],
            ['DOKTER', 'RM-6.4', true, true,  true],
            ['DOKTER', 'RM-6.5', true, true,  true],
            ['DOKTER', 'RM-7.3', true, true,  true],
            ['DOKTER', 'RM-8.1', true, true,  true],
            ['DOKTER', 'RM-8.2', true, true,  true],

            // PENUNJANG
            ['PENUNJANG', 'RM-3.1', false, false, true],
            ['PENUNJANG', 'RM-3.2', true,  true,  true],

            // BEDAH
            ['BEDAH', 'RM-5.2', true,  true,  true],
            ['BEDAH', 'RM-5.3', true,  true,  true],
            ['BEDAH', 'RM-5.4', true,  true,  true],
            ['BEDAH', 'RM-8.1', false, false, true],
            ['BEDAH', 'RM-8.2', false, false, true],

            // FARMASI
            ['FARMASI', 'RM-4.1', false, false, true],
            ['FARMASI', 'RM-4.2', false, false, true],

            // KASIR
            ['KASIR', 'RM-1.2', false, false, true],
            ['KASIR', 'RM-7.1', false, false, true],
            ['KASIR', 'RM-7.2', true,  true,  true],
        ];

        foreach ($mappings as [$station, $code, $canCreate, $canSubmit, $canPrint]) {
            $docTypeId = $id($code);
            if (! $docTypeId) {
                continue;
            }

            DB::table('station_document_mappings')->updateOrInsert(
                ['station' => $station, 'document_type_id' => $docTypeId],
                [
                    'id'               => \Illuminate\Support\Str::uuid(),
                    'is_available'     => true,
                    'can_create'       => $canCreate,
                    'can_submit'       => $canSubmit,
                    'can_print'        => $canPrint,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]
            );
        }
    }
}
