<?php

namespace Database\Seeders;

use App\Models\IntegrationConfig;
use Illuminate\Database\Seeder;

class IntegrationConfigSeeder extends Seeder
{
    public function run(): void
    {
        // Base URL & service name DEV BPJS (Docs/BRIDGING VCLAIM.md §BASE URL).
        // credentials di-scaffold KOSONG agar admin tahu field apa yang harus diisi
        // di menu Integrasi; nilai sebenarnya (cons_id/secret_key/user_key) diisi via UI.
        $systems = [
            [
                'system_name'   => 'VCLAIM',
                'base_url'      => 'https://apijkn-dev.bpjs-kesehatan.go.id',
                'configuration' => ['service_name' => 'vclaim-rest-dev', 'kode_faskes' => '', 'timeout' => 30],
                'notes'         => 'BPJS VClaim — Cek Peserta, SEP, Rujukan, Surat Kontrol, LPK, Monitoring',
            ],
            [
                'system_name'   => 'ANTREAN',
                'base_url'      => 'https://apijkn-dev.bpjs-kesehatan.go.id',
                'configuration' => ['service_name' => 'antreanrs_dev', 'kode_faskes' => '', 'timeout' => 30],
                'notes'         => 'BPJS Antrean RS — Add/UpdateWaktu/Batal antrean, sinkron jadwal dokter, validasi booking JKN Mobile',
            ],
            [
                'system_name'   => 'ICARE',
                'base_url'      => 'https://apijkn-dev.bpjs-kesehatan.go.id',
                'configuration' => ['service_name' => 'ihs_dev', 'timeout' => 30],
                'notes'         => 'BPJS iCare — Riwayat pelayanan & utilisasi peserta',
            ],
            [
                'system_name'   => 'REKAM_MEDIS',
                'base_url'      => 'https://apijkn-dev.bpjs-kesehatan.go.id',
                // service family 'ihs' (sama i-Care). kode_faskes (PPK) dipakai sbg
                // koders saat enkripsi dataMR; kosong → fallback VCLAIM/ClinicProfile.
                'configuration' => ['service_name' => 'ihs_dev', 'kode_faskes' => '', 'timeout' => 60],
                'notes'         => 'BPJS WS Rekam Medis — Insert RME (eclaim/rekammedis/insert) → mengisi i-Care',
            ],
            [
                'system_name'   => 'LUPIS',
                'base_url'      => null,
                'configuration' => null,
                'notes'         => 'BPJS LUPIS — Laporan utilisasi pelayanan',
            ],
            [
                'system_name'   => 'INACBGS',
                'base_url'      => null,
                'configuration' => null,
                'notes'         => 'INA-CBGs Grouper — Pengelompokan kode tarif klaim',
            ],
            [
                'system_name'   => 'APLICARE',
                'base_url'      => 'https://apijkn-dev.bpjs-kesehatan.go.id',
                // service_name = path layanan Aplicares (Docs/Briding Aplicare dan Apotek.docx
                // → {Base URL}/aplicaresws/rest/...). cons_id/secret_key boleh sama VClaim,
                // user_key Aplicare TERPISAH (key layanan Aplicare dari BPJS).
                'configuration' => ['service_name' => 'aplicaresws/rest', 'kode_faskes' => '', 'timeout' => 30],
                'notes'         => 'BPJS Aplicare — Ketersediaan Tempat Tidur (sinkron bed rawat inap per kelas).',
            ],
            [
                'system_name'   => 'APOTEK_ONLINE',
                'base_url'      => 'https://apijkn-dev.bpjs-kesehatan.go.id',
                // service_name = path layanan Apotek Online (DPHO, resep, pelayanan obat PRB/
                // kronis/kemo). user_key Apotek Online TERPISAH dari VClaim.
                'configuration' => ['service_name' => 'apotek-rest-dev', 'kode_faskes' => '', 'timeout' => 30],
                'notes'         => 'BPJS Apotek Online — Klaim obat PRB/Kronis/Kemoterapi (referensi DPHO, resep, pelayanan obat). Fase 0: referensi & test koneksi.',
            ],
            [
                'system_name'   => 'SATUSEHAT',
                // Base URL Sandbox/Staging Kemenkes (non-secret). Production:
                // https://api-satusehat.kemkes.go.id — diganti admin lewat UI.
                'base_url'      => 'https://api-satusehat-stg.dto.kemkes.go.id',
                // env + organization_id + location_id non-secret. client_id/client_secret
                // (rahasia) diisi admin via UI Konfigurasi → masuk ke `credentials` (encrypted).
                'configuration' => ['env' => 'sandbox', 'organization_id' => '', 'location_id' => '', 'timeout' => 30],
                'notes'         => 'Satu Sehat — Sync rekam medis (Encounter/Condition/MedicationRequest/MedicationDispense) ke platform nasional',
            ],
        ];

        foreach ($systems as $system) {
            $existing = IntegrationConfig::where('system_name', $system['system_name'])->first();

            IntegrationConfig::updateOrCreate(
                ['system_name' => $system['system_name']],
                [
                    // Jangan timpa toggle/credential yang sudah diisi admin.
                    'is_enabled'    => $existing->is_enabled ?? false,
                    'base_url'      => $existing && $existing->base_url ? $existing->base_url : $system['base_url'],
                    'credentials'   => $existing->credentials ?? null,
                    'configuration' => $existing->configuration ?? $system['configuration'],
                    'notes'         => $system['notes'],
                ]
            );
        }
    }
}
