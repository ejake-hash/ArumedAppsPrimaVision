<?php

namespace Database\Seeders;

use App\Models\Bed;
use App\Models\ClinicProfile;
use App\Models\Employee;
use App\Models\Insurer;
use App\Models\Medication;
use App\Models\NurseCpptEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Room;
use App\Models\RoomTariff;
use App\Models\Visit;
use App\Services\RanapService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RanapDemoSeeder — data demo Rawat Inap untuk uji manual / end-to-end.
 *
 * Membuat:
 *   - Master: 4 Room (VIP/Kls 1/2/3) + bed, + tarif kamar (UMUM/BPJS) + tarif tindakan demo.
 *   - 2 pasien "Menunggu Kamar" (planning RAWAT_INAP → current_station=MENUNGGU_RANAP).
 *   - 5 pasien rawat inap AKTIF di berbagai kelas (1 titip kelas), dgn visite/tindakan.
 *
 * No. RM memakai FORMAT ASLI sistem ({YYYYMM}{seq}, dari clinic_profiles.rm_last_seq),
 * sama seperti AdmisiService::generateNoRM — bukan penanda hardcode.
 *
 * IDEMPOTEN: pasien di-match via NIK (stabil); RM hanya digenerate untuk pasien baru.
 * Jalankan: php artisan db:seed --class=RanapDemoSeeder
 */
class RanapDemoSeeder extends Seeder
{
    public function run(): void
    {
        $umumId = Insurer::where('is_system', true)->where('type', 'UMUM')->value('id');
        $bpjsId = Insurer::where('is_system', true)->where('type', 'BPJS')->value('id');
        $dpjp   = Employee::where('profession', 'like', 'Dokter%')->first();

        // PPA untuk demo CPPT terintegrasi. Apoteker dibuat bila belum ada,
        // agar timeline demo benar-benar lintas-profesi (bukan fallback DPJP).
        $perawat  = Employee::where('profession', 'like', '%Perawat%')->first() ?? $dpjp;
        $apoteker = Employee::where('profession', 'like', '%Apoteker%')->first()
                    ?? Employee::where('profession', 'like', '%Farmasi%')->first()
                    ?? Employee::firstOrCreate(
                        ['nip' => 'DEMO-APT-01'],
                        ['name' => 'apt. Dewi Lestari S.Farm', 'profession' => 'Apoteker', 'is_active' => true]
                    );

        // ── Master Room + Bed ────────────────────────────────────────────────
        $roomDefs = [
            ['code' => 'VIP-1', 'name' => 'VIP Anggrek 1', 'kelas' => 'VIP', 'type' => 'KAMAR', 'beds' => ['A']],
            ['code' => '201',   'name' => 'Ruang 201',     'kelas' => '1',   'type' => 'KAMAR', 'beds' => ['A', 'B']],
            ['code' => '305',   'name' => 'Ruang 305',     'kelas' => '2',   'type' => 'KAMAR', 'beds' => ['A', 'B', 'C']],
            ['code' => '410',   'name' => 'Ruang 410',     'kelas' => '3',   'type' => 'KAMAR', 'beds' => ['A', 'B', 'C', 'D']],
        ];
        $rooms = [];
        foreach ($roomDefs as $rd) {
            $room = Room::firstOrCreate(
                ['code' => $rd['code']],
                ['name' => $rd['name'], 'kelas_rawat' => $rd['kelas'], 'type' => $rd['type'], 'is_active' => true]
            );
            foreach ($rd['beds'] as $code) {
                Bed::firstOrCreate(
                    ['room_id' => $room->id, 'code' => $code],
                    ['label' => "{$room->code}.{$code}", 'status' => Bed::STATUS_AVAILABLE, 'is_active' => true]
                );
            }
            $rooms[$rd['kelas']] = $room;
        }

        // ── Tarif kamar per kelas (UMUM + BPJS) ──────────────────────────────
        $tarif = ['VIP' => 800000, '1' => 500000, '2' => 350000, '3' => 200000];
        foreach ($tarif as $kelas => $harga) {
            RoomTariff::updateOrCreate(
                ['room_class' => $kelas, 'insurer_id' => $umumId, 'classification' => 'UMUM'],
                ['price' => $harga, 'is_active' => true]
            );
            RoomTariff::updateOrCreate(
                ['room_class' => $kelas, 'insurer_id' => $bpjsId, 'classification' => 'BPJS'],
                ['price' => $harga * 0.9, 'is_active' => true]
            );
        }

        // ── Tarif tindakan UMUM (demo) — agar picker tindakan punya harga ────
        if ($umumId) {
            foreach (Procedure::where('is_active', true)->get(['id']) as $i => $proc) {
                $exists = DB::table('procedure_tariffs')
                    ->where('procedure_id', $proc->id)->where('insurer_id', $umumId)->exists();
                if (! $exists) {
                    DB::table('procedure_tariffs')->insert([
                        'id'           => (string) Str::uuid(),
                        'procedure_id' => $proc->id,
                        'insurer_id'   => $umumId,
                        'price'        => 100000 + ($i * 25000),
                        'is_active'    => true,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }
        }

        // ── Master Obat demo + tarif (agar picker obat RANAP punya isi & harga) ──
        $obatDemo = [
            ['code' => 'OBT-001', 'name' => 'Paracetamol 500mg',        'unit' => 'Tablet', 'price' => 1500],
            ['code' => 'OBT-002', 'name' => 'Amoxicillin 500mg',        'unit' => 'Kapsul', 'price' => 2500],
            ['code' => 'OBT-003', 'name' => 'Cefixime 100mg',           'unit' => 'Kapsul', 'price' => 4000],
            ['code' => 'OBT-004', 'name' => 'Ranitidine 150mg',         'unit' => 'Tablet', 'price' => 1800],
            ['code' => 'OBT-005', 'name' => 'Ketorolac 30mg/ml',        'unit' => 'Ampul',  'price' => 12000],
            ['code' => 'OBT-006', 'name' => 'Infus RL 500ml',           'unit' => 'Botol',  'price' => 18000],
            ['code' => 'OBT-007', 'name' => 'Cendo Xitrol Tetes Mata',  'unit' => 'Botol',  'price' => 45000],
            ['code' => 'OBT-008', 'name' => 'Timol 0.5% Tetes Mata',    'unit' => 'Botol',  'price' => 38000],
            ['code' => 'OBT-009', 'name' => 'Ondansetron 4mg',          'unit' => 'Ampul',  'price' => 9500],
            ['code' => 'OBT-010', 'name' => 'Omeprazole 20mg',          'unit' => 'Kapsul', 'price' => 3200],
        ];
        foreach ($obatDemo as $od) {
            $med = Medication::firstOrCreate(
                ['code' => $od['code']],
                ['name' => $od['name'], 'unit' => $od['unit'], 'formularium' => 'NON-FORNAS', 'price' => $od['price'], 'is_active' => true]
            );
            // Tarif jual per penjamin (UMUM + BPJS) agar getPrice('medication',...) menemukan harga.
            foreach ([['id' => $umumId, 'p' => $od['price']], ['id' => $bpjsId, 'p' => $od['price']]] as $t) {
                if (! $t['id']) {
                    continue;
                }
                $exists = DB::table('medication_tariffs')
                    ->where('medication_id', $med->id)->where('insurer_id', $t['id'])->exists();
                if (! $exists) {
                    DB::table('medication_tariffs')->insert([
                        'id'            => (string) Str::uuid(),
                        'medication_id' => $med->id,
                        'insurer_id'    => $t['id'],
                        'price'         => $t['p'],
                        'is_active'     => true,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }
        }

        $svc = app(RanapService::class);

        // Reset bed demo yang nyangkut CLEANING/RESERVED (sisa test) ke AVAILABLE,
        // KECUALI yang sedang OCCUPIED (ada pasien aktif). Supaya seeder selalu
        // punya bed kosong untuk admit pasien demo baru.
        Bed::whereIn('status', [Bed::STATUS_CLEANING, Bed::STATUS_RESERVED])
            ->whereDoesntHave('bedAssignments', fn ($q) => $q->whereNull('released_at'))
            ->update(['status' => Bed::STATUS_AVAILABLE]);

        // ── Pasien MENUNGGU KAMAR (planning RAWAT_INAP) ──────────────────────
        // nik unik per pasien demo (prefix 9999 = penanda data uji).
        $this->makeWaiting('9999000001', 'Hartono Susilo',  'L', '1955-03-12', 'UMUM', $umumId);
        $this->makeWaiting('9999000002', 'Dewi Anggraini',  'P', '1978-07-25', 'BPJS', $bpjsId);

        // ── Pasien RAWAT INAP AKTIF (beragam kelas) ──────────────────────────
        $aktif = [
            // [nik, nama, gender, dob, penjamin, insurer, kelas_room, kelas_hak, hari_lalu]
            ['9999000010', 'Bambang Sutrisno', 'L', '1962-01-30', 'UMUM', $umumId, '2',   '2',   3],
            ['9999000011', 'Sri Mulyani',      'P', '1970-11-08', 'BPJS', $bpjsId, '2',   '1',   2], // TITIP: room Kls 2, hak Kls 1
            ['9999000012', 'Agus Salim',       'L', '1958-06-17', 'BPJS', $bpjsId, '3',   '3',   4],
            ['9999000013', 'Ratna Kusuma',     'P', '1985-09-02', 'UMUM', $umumId, '1',   '1',   1],
            ['9999000014', 'Joko Widodo',      'L', '1949-12-21', 'UMUM', $umumId, 'VIP', 'VIP', 5],
        ];
        foreach ($aktif as $a) {
            [$nik, $nama, $gender, $dob, $penjamin, $insurerId, $kelasRoom, $kelasHak, $hari] = $a;
            $v = $this->makeVisit($nik, $nama, $gender, $dob, $penjamin, $insurerId);

            // Admit hanya bila belum dirawat (idempoten); pasien yang sudah
            // RANAP dari run sebelumnya tetap di-backfill CPPT-nya di bawah.
            if (($v->jenis_pelayanan ?? 'RAJAL') !== 'RANAP') {
                $bed = $this->firstAvailableBedInRoom($rooms[$kelasRoom] ?? null);
                if (! $bed) {
                    continue; // bed kelas itu penuh
                }
                $v = $svc->admit($v, $bed->id, $kelasHak, $dpjp?->id, now()->subDays($hari)->toIso8601String());
                // Visite harian + 1 tindakan contoh.
                for ($d = $hari; $d >= 1; $d--) {
                    $svc->addVisite($v->fresh(), [
                        'description' => 'Visite dokter — hari ke-' . ($hari - $d + 1),
                        'unit_price'  => 150000,
                        'charge_date' => now()->subDays($d)->toDateString(),
                    ]);
                }
                $proc = Procedure::where('is_active', true)->inRandomOrder()->first();
                if ($proc) {
                    $svc->addTindakan($v->fresh(), $proc->id, 1);
                }
            }

            // CPPT terintegrasi multi-PPA (idempoten per-visit via exists()).
            $this->seedCpptTerintegrasi($v->fresh(), $dpjp, $perawat, $apoteker, $hari);
        }

        $this->command?->info('RanapDemoSeeder selesai: 4 room + tarif, 2 menunggu kamar, 5 rawat inap aktif (1 titip kelas) + CPPT terintegrasi multi-PPA. No. RM format asli.');
    }

    /**
     * CPPT terintegrasi multi-PPA contoh: DPJP (SOAP, terverifikasi),
     * Perawat (observasi TTV), Apoteker (telaah obat). Idempoten per-visit.
     * ppa_role di-set eksplisit (seeder tak punya auth user).
     */
    private function seedCpptTerintegrasi(Visit $v, ?Employee $dpjp, ?Employee $perawat, ?Employee $apoteker, int $hari): void
    {
        if (NurseCpptEntry::where('visit_id', $v->id)->exists()) {
            return; // sudah ada — idempoten
        }

        $base = now()->subDays(max(1, $hari - 1));

        // Tiap entri demo MENGISI SEMUA KOLOM (SOAP lengkap + instruksi + TTV
        // lengkap termasuk KGD & skala nyeri) agar UI menampilkan tiap field.

        // 1) Visite DPJP (SOAP lengkap + status mata) — diverifikasi DPJP.
        // soap_o sengaja PANJANG untuk mendemokan clamp "Selengkapnya".
        NurseCpptEntry::create([
            'visit_id'       => $v->id,
            'ppa_role'       => Employee::PPA_DOKTER,
            'td_sistol'      => 128, 'td_diastol' => 82, 'nadi' => 80, 'suhu' => 36.5,
            'respirasi'      => 18, 'spo2' => 99, 'kgd' => 110, 'pain_scale' => 2,
            'visus_od'       => '6/60', 'visus_os' => '6/6', 'iop_od' => 16.5, 'iop_os' => 15.0, 'iop_method' => 'NCT',
            'soap_s'         => 'Pasien mengeluh penglihatan mata kanan masih buram, silau (+) terutama saat siang hari. Tidak ada nyeri, mata merah, maupun riwayat trauma.',
            'soap_o'         => 'VOD 6/60 ph tetap, VOS 6/6. Segmen anterior OD: konjungtiva tenang, kornea jernih, COA dalam, pupil bulat sentral refleks (+), lensa keruh merata derajat matur. Segmen anterior OS dalam batas normal. TIO OD 16,5 mmHg, OS 15,0 mmHg (NCT). Funduskopi OD sulit dinilai karena kekeruhan lensa; OS papil batas tegas, CDR 0,3, makula refleks fovea (+).',
            'soap_a'         => 'Katarak senilis matur OD. OS dalam batas normal.',
            'soap_p'         => 'Persiapan operasi fakoemulsifikasi + IOL OD. Lengkapi biometri (A-scan/IOL Master) & evaluasi pra-anestesi.',
            'instruksi'      => 'Tetes antibiotik (levofloxacin) 4x1 OD, kontrol gula darah pagi hari, puasa 6 jam pra-operasi, lapor bila TIO meningkat atau nyeri hebat.',
            'notes'          => 'Edukasi prosedur, risiko, dan informed consent telah diberikan kepada pasien dan keluarga; pasien menyetujui rencana operasi.',
            'created_by_id'  => $dpjp?->id,
            'verified_by_id' => $dpjp?->id,
            'verified_at'    => $base->copy()->addHours(2),
            'created_at'     => $base->copy()->addHours(1),
            'updated_at'     => $base->copy()->addHours(2),
        ]);

        // 2) Observasi perawat (asuhan keperawatan + TTV lengkap).
        NurseCpptEntry::create([
            'visit_id'      => $v->id,
            'ppa_role'      => Employee::PPA_PERAWAT,
            'td_sistol'     => 130, 'td_diastol' => 80, 'nadi' => 84, 'suhu' => 36.6,
            'respirasi'     => 20, 'spo2' => 98, 'kgd' => 124, 'pain_scale' => 3,
            'visus_od'      => '6/60', 'visus_os' => '6/6', 'iop_od' => 17.0, 'iop_os' => 15.0, 'iop_method' => 'NCT',
            'soap_s'        => 'Pasien mengatakan dapat tidur semalam, nyeri ringan area mata.',
            'soap_o'        => 'KU baik, kesadaran kompos mentis, akral hangat, terpasang infus RL 20 tpm.',
            'soap_a'        => 'Risiko jatuh sedang; gangguan rasa nyaman (nyeri ringan).',
            'soap_p'        => 'Observasi TTV per shift, edukasi mobilisasi, pasang penanda risiko jatuh.',
            'instruksi'     => 'Pantau intake-output cairan, lapor DPJP bila nyeri skala > 4.',
            'notes'         => 'Pasien kooperatif, keluarga mendampingi.',
            'created_by_id' => $perawat?->id,
            'created_at'    => $base->copy()->addHours(4),
            'updated_at'    => $base->copy()->addHours(4),
        ]);

        // 3) Telaah apoteker (rekonsiliasi obat) — belum diverifikasi.
        NurseCpptEntry::create([
            'visit_id'      => $v->id,
            'ppa_role'      => Employee::PPA_APOTEKER,
            'td_sistol'     => 130, 'td_diastol' => 80, 'nadi' => 82, 'suhu' => 36.5,
            'respirasi'     => 19, 'spo2' => 98, 'kgd' => 118, 'pain_scale' => 1,
            'soap_s'        => 'Pasien menanyakan jadwal & cara pemakaian obat tetes mata.',
            'soap_o'        => 'Daftar obat: levofloxacin ED, prednisolone ED, metformin 500mg po.',
            'soap_a'        => 'Rekonsiliasi obat: tidak ditemukan interaksi bermakna; kepatuhan baik.',
            'soap_p'        => 'Lanjut terapi sesuai instruksi DPJP; pantau fungsi ginjal pada terapi metformin.',
            'instruksi'     => 'Berikan obat tetes sesuai jadwal (jeda 5 menit antar tetes), dokumentasikan pemberian.',
            'notes'         => 'Edukasi teknik pemakaian obat tetes mata telah diberikan.',
            'created_by_id' => $apoteker?->id,
            'created_at'    => $base->copy()->addHours(6),
            'updated_at'    => $base->copy()->addHours(6),
        ]);
    }

    private function makeWaiting(string $nik, string $name, string $gender, string $dob, string $guarantor, ?string $insurerId): void
    {
        $visit = $this->makeVisit($nik, $name, $gender, $dob, $guarantor, $insurerId);
        if ($visit->current_station !== 'MENUNGGU_RANAP' && ($visit->jenis_pelayanan ?? 'RAJAL') !== 'RANAP') {
            $visit->update(['current_station' => 'MENUNGGU_RANAP']);
        }
    }

    /** Pasien (idempoten via NIK; RM format asli) + visit dasar. */
    private function makeVisit(string $nik, string $name, string $gender, string $dob, string $guarantor, ?string $insurerId): Visit
    {
        $patient = Patient::where('nik', $nik)->first();
        if (! $patient) {
            $patient = Patient::create([
                'no_rm'         => $this->generateNoRM(),
                'nik'           => $nik,
                'name'          => $name,
                'gender'        => $gender,
                'date_of_birth' => $dob,
                'is_active'     => true,
            ]);
        }

        return Visit::firstOrCreate(
            ['no_registrasi' => 'REG-' . $nik],
            [
                'patient_id'      => $patient->id,
                'visit_date'      => today(),
                'jenis_pelayanan' => 'RAJAL',
                'classification'  => $guarantor,
                'guarantor_type'  => $guarantor,
                'insurer_id'      => $insurerId,
                'current_station' => 'DOKTER',
            ]
        );
    }

    /** Replika AdmisiService::generateNoRM — {YYYYMM}{seq} dari clinic_profiles. */
    private function generateNoRM(): string
    {
        $noRm = '';
        DB::transaction(function () use (&$noRm) {
            $clinic = ClinicProfile::lockForUpdate()->firstOrFail();
            $pad    = $clinic->rm_seq_length ?? 4;
            $prefix = now()->format('Ym');
            $seq    = $clinic->rm_last_seq;

            for ($i = 0; $i < 100; $i++) {
                $seq++;
                $candidate = $prefix . str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);
                if (! Patient::withTrashed()->where('no_rm', $candidate)->exists()) {
                    $noRm = $candidate;
                    $clinic->update(['rm_last_seq' => $seq]);
                    return;
                }
            }
            throw new \RuntimeException('Gagal generate no_rm unik.');
        });

        return $noRm;
    }

    private function firstAvailableBedInRoom(?Room $room): ?Bed
    {
        if (! $room) {
            return null;
        }
        return Bed::where('room_id', $room->id)->where('status', Bed::STATUS_AVAILABLE)->orderBy('code')->first();
    }
}
