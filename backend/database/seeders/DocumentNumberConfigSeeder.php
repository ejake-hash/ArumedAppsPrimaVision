<?php

namespace Database\Seeders;

use App\Models\DocumentNumberConfig;
use Illuminate\Database\Seeder;

/**
 * DocumentNumberConfigSeeder — format penomoran dokumen default.
 *
 * Idempotent (firstOrCreate per kode). Token format yang didukung generator
 * (RekamMedisService::generateDocumentNumber): {CODE} {CLINIC} {SEQ} {YYYY} {MM} {DD}.
 * Admin dapat menyesuaikan lewat menu Pengaturan → Penomoran Dokumen.
 */
class DocumentNumberConfigSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['document_type_code' => 'RME',     'format' => 'RME/{CLINIC}/{YYYY}{MM}/{SEQ}', 'reset_period' => 'MONTHLY', 'seq_length' => 6],
            ['document_type_code' => 'INVOICE', 'format' => 'INV/{CLINIC}/{YYYY}{MM}/{SEQ}', 'reset_period' => 'MONTHLY', 'seq_length' => 6],
            ['document_type_code' => 'SEP',     'format' => 'SEP/{CLINIC}/{SEQ}',            'reset_period' => 'YEARLY',  'seq_length' => 7],
            ['document_type_code' => 'RUJUKAN', 'format' => 'RJK/{CLINIC}/{YYYY}/{SEQ}',     'reset_period' => 'YEARLY',  'seq_length' => 5],
            ['document_type_code' => 'SURAT',   'format' => 'SRT/{CLINIC}/{YYYY}{MM}/{SEQ}', 'reset_period' => 'MONTHLY', 'seq_length' => 5],
        ];

        foreach ($defaults as $cfg) {
            DocumentNumberConfig::firstOrCreate(
                ['document_type_code' => $cfg['document_type_code']],
                [
                    'format'       => $cfg['format'],
                    'prefix'       => null,
                    'reset_period' => $cfg['reset_period'],
                    'seq_length'   => $cfg['seq_length'],
                    'last_seq'     => 0,
                ]
            );
        }
    }
}
