<?php

namespace Database\Seeders;

use App\Models\IntegrationConfig;
use Illuminate\Database\Seeder;

class IntegrationConfigSeeder extends Seeder
{
    public function run(): void
    {
        $systems = [
            [
                'system_name'   => 'VCLAIM',
                'notes'         => 'BPJS VClaim — Generate SEP, Klaim, Rujukan',
            ],
            [
                'system_name'   => 'ANTREAN',
                'notes'         => 'BPJS Antrean — Validasi kode booking JKN Mobile',
            ],
            [
                'system_name'   => 'ICARE',
                'notes'         => 'BPJS iCare — Monitoring klaim & utilisasi',
            ],
            [
                'system_name'   => 'LUPIS',
                'notes'         => 'BPJS LUPIS — Laporan utilisasi pelayanan',
            ],
            [
                'system_name'   => 'INACBGS',
                'notes'         => 'INA-CBGs Grouper — Pengelompokan kode tarif klaim',
            ],
            [
                'system_name'   => 'SATUSEHAT',
                'notes'         => 'Satu Sehat — Sync rekam medis elektronik ke platform nasional',
            ],
        ];

        foreach ($systems as $system) {
            IntegrationConfig::updateOrCreate(
                ['system_name' => $system['system_name']],
                [
                    'is_enabled'       => false,
                    'base_url'         => null,
                    'credentials'      => null,
                    'configuration'    => null,
                    'last_test_status' => null,
                    'notes'            => $system['notes'],
                ]
            );
        }
    }
}
