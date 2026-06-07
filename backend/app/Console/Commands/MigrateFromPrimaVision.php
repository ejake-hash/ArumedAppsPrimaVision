<?php

namespace App\Console\Commands;

use App\Models\DoctorExamination;
use App\Models\Employee;
use App\Models\Insurer;
use App\Models\Patient;
use App\Models\RefractionRecord;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi data historis SIMRS lama Prima Vision → Arumed.
 *
 * Sumber  : CSV gzip hasil export Prima Vision di `Docs/migrasi data/csv/*.csv.gz`
 *           (TIDAK butuh restore Postgres — dibaca streaming langsung).
 * Target  : DB Arumed aktif (lihat .env, mis. dbprimavision).
 *
 * Idempotent: setiap baris di-`updateOrCreate` by `legacy_uuid`, jadi aman di-run
 * ulang. Urutan menghormati FK: insurers → employees → patients → visits →
 * refraction_records → doctor_examinations.
 *
 * Keputusan & temuan (lihat Docs/migrasi data/OBSERVASI.md & MAPPING_FASE3.md):
 *  - delete_soft semua = 1 (aktif) di pasien/registrasi/ro → deleted_at NULL.
 *  - gender enum Arumed = L/P.
 *  - NIK hanya 16 digit; selain itu identity_type + nik mentah / NULL.
 *  - autoref sangat kotor → parse toleran + simpan string asli ke raw_data jsonb.
 *  - registrasi_uuid bisa duplikat di ro/dokter (visit_id UNIQUE) → ambil terbaru.
 *  - classification historis = 'Baru'; current_station = 'SELESAI'.
 *  - users (akun login) TIDAK dimigrasi (dibuat manual via UI RBAC).
 *
 * Contoh:
 *   php artisan migrasi:primavision --dry-run
 *   php artisan migrasi:primavision --only=insurers,employees,patients
 *   php artisan migrasi:primavision
 */
class MigrateFromPrimaVision extends Command
{
    protected $signature = 'migrasi:primavision
                            {--only= : Daftar tabel dipisah koma (insurers,employees,patients,visits,refraksi,dokter). Default: semua}
                            {--dry-run : Hitung & validasi tanpa menulis ke DB}
                            {--limit=0 : Batasi N baris per tabel (untuk uji sample). 0 = semua}';

    protected $description = 'Migrasi data historis Prima Vision (CSV gzip) ke Arumed. Idempotent via legacy_uuid.';

    /** Path folder CSV relatif ke base_path. */
    private string $csvDir;

    private bool $dry = false;
    private int $limit = 0;

    /** Cache lookup legacy_uuid → id Arumed. */
    private array $insurerByName = [];     // lower(name) => id
    private array $employeeByLegacy = [];  // legacy_uuid => id
    private array $patientByLegacy = [];   // legacy_uuid => id
    private array $visitByLegacy = [];     // legacy_uuid => id
    private array $nikSeen = [];           // nik => true (first-wins, sumber punya 335 NIK duplikat)

    // Resolusi dokter BY-NAMA (employees arumed tak punya legacy_uuid runningprima → employeeByLegacy kosong).
    private array $employeeByNameExact = []; // lower(trim(name)) => id
    private array $employeeByNameNorm = [];  // normName(name) => id (fallback fuzzy)
    private array $doctorAlias = [];         // lower(nama sumber) => lower(nama arumed kanonik)
    private array $unresolvedDoctor = [];    // nama sumber tak ter-resolve => count (laporan)
    private int $doctorByName = 0;           // jumlah doctor_id ter-resolve by-nama

    public function handle(): int
    {
        // CSV ada di root repo (Docs/migrasi data/csv), satu level di atas backend/.
        $candidates = [
            base_path('Docs/migrasi data/csv'),          // jika backend = root
            dirname(base_path()) . '/Docs/migrasi data/csv', // repo/Docs, backend nested
        ];
        $this->csvDir = '';
        foreach ($candidates as $c) {
            if (is_dir($c)) { $this->csvDir = $c; break; }
        }
        $this->dry = (bool) $this->option('dry-run');
        $this->limit = (int) $this->option('limit');

        if ($this->csvDir === '') {
            $this->error('Folder CSV tidak ditemukan. Dicari di: ' . implode(' | ', $candidates));
            return self::FAILURE;
        }
        $this->line("CSV dir: {$this->csvDir}");

        $only = $this->option('only')
            ? array_map('trim', explode(',', (string) $this->option('only')))
            : ['insurers', 'employees', 'patients', 'visits', 'refraksi', 'dokter'];

        if ($this->dry) {
            $this->warn('=== DRY RUN — tidak ada penulisan ke DB ===');
        }

        // Selalu hangatkan cache lookup dari DB (agar --only sebagian tetap bisa resolve FK).
        $this->warmCaches();

        try {
            foreach ($only as $step) {
                match ($step) {
                    'insurers'  => $this->migrateInsurers(),
                    'employees' => $this->migrateEmployees(),
                    'patients'  => $this->migratePatients(),
                    'visits'    => $this->migrateVisits(),
                    'refraksi'  => $this->migrateRefraksi(),
                    'dokter'    => $this->migrateDokter(),
                    default     => $this->warn("Lewati langkah tak dikenal: {$step}"),
                };
            }
        } catch (\Throwable $e) {
            $this->error("GAGAL: {$e->getMessage()}");
            $this->line($e->getFile() . ':' . $e->getLine());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info($this->dry ? 'Dry run selesai.' : 'Migrasi selesai.');
        return self::SUCCESS;
    }

    // ───────────────────────────────────────────────────────────── caches

    private function warmCaches(): void
    {
        foreach (Insurer::query()->get(['id', 'name']) as $i) {
            $this->insurerByName[mb_strtolower($i->name)] = $i->id;
        }
        foreach (Employee::query()->whereNotNull('legacy_uuid')->get(['id', 'legacy_uuid']) as $e) {
            $this->employeeByLegacy[$e->legacy_uuid] = $e->id;
        }
        foreach (Patient::query()->whereNotNull('legacy_uuid')->get(['id', 'legacy_uuid']) as $p) {
            $this->patientByLegacy[$p->legacy_uuid] = $p->id;
        }
        // NIK yang sudah terpakai di DB → agar re-run tidak bentrok nik UNIQUE.
        foreach (Patient::query()->whereNotNull('nik')->pluck('nik') as $nik) {
            $this->nikSeen[$nik] = true;
        }
        foreach (Visit::query()->whereNotNull('legacy_uuid')->get(['id', 'legacy_uuid']) as $v) {
            $this->visitByLegacy[$v->legacy_uuid] = $v->id;
        }
        // Resolusi dokter by-nama: peta nama pegawai arumed (exact + ternormalisasi) + alias terkurasi.
        foreach (Employee::query()->get(['id', 'name']) as $e) {
            $this->employeeByNameExact[mb_strtolower(trim((string) $e->name))] = $e->id;
            $nn = $this->normName($e->name);
            if ($nn !== '' && ! isset($this->employeeByNameNorm[$nn])) {
                $this->employeeByNameNorm[$nn] = $e->id;
            }
        }
        $this->loadDoctorAlias();
    }

    // ─────────────────────────────────────────────────────────── insurers

    private function migrateInsurers(): void
    {
        $this->newLine();
        $this->info('▶ insurers (carabayar + asuransi)');
        $created = 0; $skipped = 0;

        // Gabung carabayar (perusahaan/penjamin) + asuransi (anak TPA).
        $rows = [];
        foreach ($this->readCsv('carabayar') as $r) {
            $rows[] = ['uuid' => $r['uuid'], 'name' => $r['nama'], 'src' => 'carabayar'];
        }
        foreach ($this->readCsv('asuransi') as $r) {
            $rows[] = ['uuid' => $r['uuid'], 'name' => $r['nama'], 'src' => 'asuransi'];
        }

        $bar = $this->output->createProgressBar(count($rows));
        foreach ($rows as $r) {
            $name = $this->clean($r['name']);
            if ($name === null) { $skipped++; $bar->advance(); continue; }

            // Skip yang sudah jadi insurer sistem (UMUM / BPJS*).
            $lname = mb_strtolower($name);
            if ($lname === 'umum' || str_starts_with($lname, 'bpjs')) { $skipped++; $bar->advance(); continue; }
            if (isset($this->insurerByName[$lname])) { $skipped++; $bar->advance(); continue; }

            $type = $this->guessInsurerType($name, $r['src']);
            if (! $this->dry) {
                $ins = Insurer::updateOrCreate(
                    ['legacy_uuid' => $r['uuid']],
                    ['name' => $name, 'type' => $type, 'is_active' => true, 'is_system' => false]
                );
                $this->insurerByName[$lname] = $ins->id;
            }
            $created++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->line("  dibuat/diupdate: {$created} · dilewati (sistem/duplikat/kosong): {$skipped}");
    }

    private function guessInsurerType(string $name, string $src): string
    {
        $n = mb_strtoupper($name);
        if (str_contains($n, 'PT') || str_contains($n, 'PLN') || str_contains($n, 'TBK')) {
            return 'PERUSAHAAN';
        }
        return 'ASURANSI';
    }

    // ────────────────────────────────────────────────────────── employees

    private function migrateEmployees(): void
    {
        $this->newLine();
        $this->info('▶ employees (biodata) — akun users TIDAK dibuat (manual via UI)');
        $created = 0; $skipped = 0;

        $rows = iterator_to_array($this->readCsv('biodata'));
        $bar = $this->output->createProgressBar(count($rows));
        foreach ($rows as $r) {
            $name = $this->clean($r['nama_pengguna']);
            if ($name === null) { $skipped++; $bar->advance(); continue; }

            $email = $this->clean($r['email_pengguna'] ?? null);
            if ($email !== null && Employee::where('email', $email)
                    ->where('legacy_uuid', '!=', $r['uuid'])->exists()) {
                $email = null; // hindari bentrok UNIQUE
            }

            $data = [
                'name'       => $name,
                'profession' => $this->truncate($this->clean($r['sebagai_pengguna'] ?? null), 100),
                'sip'        => $this->truncate($this->clean($r['sima'] ?? null), 100),   // SIM-A ≈ SIP (konfirmasi)
                'str'        => $this->truncate($this->clean($r['simc'] ?? null), 100),   // SIM-C ≈ STR (konfirmasi)
                'phone'      => $this->truncate($this->clean($r['no_handphone'] ?? null), 20),
                'email'      => $email,
                'address'    => $this->clean($r['alamat'] ?? null),
                'is_active'  => ((string) ($r['delete_soft'] ?? '1')) === '1',
            ];

            if (! $this->dry) {
                $emp = Employee::updateOrCreate(['legacy_uuid' => $r['uuid']], $data);
                $this->employeeByLegacy[$r['uuid']] = $emp->id;
            }
            $created++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->line("  dibuat/diupdate: {$created} · dilewati: {$skipped}");
    }

    // ─────────────────────────────────────────────────────────── patients

    private function migratePatients(): void
    {
        $this->newLine();
        $this->info('▶ patients (pasien)');
        $created = 0; $skipped = 0; $n = 0;

        foreach ($this->readCsv('pasien') as $r) {
            if ($this->limit && $n >= $this->limit) break;
            $n++;

            $noRm = $this->clean($r['rekam_medis']);
            if ($noRm === null) { $skipped++; continue; }

            [$nik, $identityType] = $this->resolveNik($r);

            $data = [
                'no_rm'          => $this->truncate($noRm, 50),
                'identity_type'  => $this->truncate($identityType, 20),
                'nik'            => $this->truncate($nik, 50),
                'name'           => $this->clean($r['nama']) ?? 'TANPA NAMA',
                'gender'         => $this->mapGender($r['jenis_kelamin'] ?? null),
                'date_of_birth'  => $this->parseDob($r['tanggal_lahir'] ?? null),
                'tempat_lahir'   => $this->truncate($this->clean($r['tempat_lahir'] ?? null), 100),
                'pekerjaan'      => $this->truncate($this->clean($r['pekerjaan'] ?? null), 50),
                'phone'          => $this->truncate($this->clean($r['no_handphone'] ?? null), 20),
                'address'        => $this->clean($r['alamat'] ?? null),
                'province'       => $this->truncate($this->clean($r['nama_provinsi'] ?? null), 100),
                'nama_kab_kota'  => $this->truncate($this->clean($r['nama_kab_kota'] ?? null), 100),
                'nama_kecamatan' => $this->truncate($this->clean($r['nama_kecamatan'] ?? null), 100),
                'nama_kelurahan' => $this->truncate($this->clean($r['nama_kelurahan'] ?? null), 100),
                'blood_type'     => $this->mapBloodType($r['golongan_darah'] ?? null),
                'is_active'      => true,
            ];

            if (! $this->dry) {
                $p = Patient::updateOrCreate(['legacy_uuid' => $r['uuid']], $data);
                if (! empty($r['created_at'])) {
                    $p->timestamps = false;
                    $p->created_at = $r['created_at'];
                    $p->save();
                    $p->timestamps = true;
                }
                $this->patientByLegacy[$r['uuid']] = $p->id;
            }
            $created++;
            if ($created % 5000 === 0) $this->line("  …{$created}");
        }
        $this->line("  dibuat/diupdate: {$created} · dilewati (no_rm kosong): {$skipped}");
    }

    /**
     * Resolusi NIK: HANYA terima 16 digit numerik (KTP). no_identitas non-16-digit
     * di sumber 95% sampah ("TIDAK ADA KTP", "00", "A", dll) & banyak duplikat →
     * langgar nik UNIQUE. Jadi nik=NULL bila tak ada 16-digit valid; identity_type
     * tetap dari jenis aslinya untuk konteks. (25,8% pasien tanpa NIK — sesuai keputusan.)
     */
    private function resolveNik(array $r): array
    {
        $ktp = trim((string) ($r['no_ktp'] ?? ''));
        $ident = trim((string) ($r['no_identitas'] ?? ''));

        $nik = null;
        if (preg_match('/^[0-9]{16}$/', $ktp)) $nik = $ktp;
        elseif (preg_match('/^[0-9]{16}$/', $ident)) $nik = $ident;

        if ($nik !== null) {
            // Tolak NIK placeholder (≥8 nol beruntun di belakang, mis. xxxxxxxx00000000).
            if (preg_match('/0{8,}$/', $nik)) {
                $nik = null;
            } elseif (isset($this->nikSeen[$nik])) {
                // Duplikat (kembar/entri ganda, 335 baris di sumber) → first-wins, sisanya NULL.
                $nik = null;
            } else {
                $this->nikSeen[$nik] = true;
            }
        }

        if ($nik !== null) return [$nik, 'KTP'];

        $jenis = $this->clean($r['jenis_identitas'] ?? null);
        return [null, $this->truncate($jenis, 20) ?? 'KTP'];
    }

    private function mapGender(?string $g): ?string
    {
        $g = mb_strtoupper(trim((string) $g));
        return match (true) {
            in_array($g, ['LAKI-LAKI', 'LAKI LAKI', 'L', 'LAKI', 'PRIA'], true) => 'L',
            in_array($g, ['PEREMPUAN', 'P', 'WANITA'], true)                     => 'P',
            default                                                              => null,
        };
    }

    private function mapBloodType(?string $b): ?string
    {
        $b = $this->clean($b);
        if ($b === null) return null;
        $b = mb_strtoupper(str_replace(' ', '', $b));
        return preg_match('/^(A|B|AB|O)[+-]?$/', $b) ? $b : null;
    }

    private function parseDob(?string $d): ?string
    {
        $d = trim((string) $d);
        if ($d === '' || $d === '-' || str_starts_with($d, '1000-01-01') || str_starts_with($d, '1990-09-09')) {
            return null;
        }
        try { return Carbon::parse($d)->toDateString(); } catch (\Throwable) { return null; }
    }

    // ───────────────────────────────────────────────────────────── visits

    private function migrateVisits(): void
    {
        $this->newLine();
        $this->info('▶ visits (registrasi — header saja)');
        $created = 0; $skippedOrphan = 0; $n = 0;

        foreach ($this->readCsv('registrasi') as $r) {
            if ($this->limit && $n >= $this->limit) break;
            $n++;

            $patientId = $this->patientByLegacy[$r['pasien_uuid']] ?? null;
            if ($patientId === null) { $skippedOrphan++; continue; }

            $guarantor = $this->mapGuarantor($r['carabayar_nama'] ?? null);
            $insurerId = $this->resolveInsurerId($r['nama_asuransi'] ?? null, $r['carabayar_nama'] ?? null);
            $registeredBy = $this->employeeByLegacy[$r['pengguna_uuid']] ?? null;

            $data = [
                'patient_id'        => $patientId,
                'insurer_id'        => $insurerId,
                'registered_by_id'  => $registeredBy,
                'visit_date'        => $this->parseDob($r['tanggal'] ?? null) ?? substr((string) $r['created_at'], 0, 10),
                'classification'    => 'Baru',
                'current_station'   => 'SELESAI',
                'guarantor_type'    => $guarantor,
            ];

            if (! $this->dry) {
                $v = Visit::updateOrCreate(['legacy_uuid' => $r['uuid']], $data);
                if (! empty($r['created_at'])) {
                    $v->timestamps = false;
                    $v->created_at = $r['created_at'];
                    $v->save();
                    $v->timestamps = true;
                }
                $this->visitByLegacy[$r['uuid']] = $v->id;
            }
            $created++;
            if ($created % 5000 === 0) $this->line("  …{$created}");
        }
        $this->line("  dibuat/diupdate: {$created} · dilewati (pasien orphan): {$skippedOrphan}");
    }

    private function mapGuarantor(?string $carabayar): string
    {
        $c = mb_strtoupper(trim((string) $carabayar));
        return match (true) {
            str_starts_with($c, 'BPJS KESEHATAN') => 'BPJS',
            $c === 'UMUM'                          => 'UMUM',
            str_contains($c, 'PT') || str_contains($c, 'PLN') || str_contains($c, 'TBK') => 'PERUSAHAAN',
            $c === '' || $c === '-'                => 'UMUM',
            default                                => 'ASURANSI',
        };
    }

    private function resolveInsurerId(?string $asuransi, ?string $carabayar): ?string
    {
        foreach ([$asuransi, $carabayar] as $cand) {
            $name = $this->clean($cand);
            if ($name === null) continue;
            $id = $this->insurerByName[mb_strtolower($name)] ?? null;
            if ($id) return $id;
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────── refraksi

    private function migrateRefraksi(): void
    {
        $this->newLine();
        $this->info('▶ refraction_records (pemeriksaan_ro) — ambil 1 terbaru per registrasi');
        // visit_id UNIQUE → dedup by registrasi_uuid, ambil created_at terbaru.
        $best = $this->dedupByLatest('pemeriksaan_ro', 'registrasi_uuid');

        $created = 0; $skipped = 0; $n = 0;
        $bar = $this->output->createProgressBar(count($best));
        foreach ($best as $r) {
            if ($this->limit && $n >= $this->limit) { $bar->finish(); break; }
            $n++;

            $visitId = $this->visitByLegacy[$r['registrasi_uuid']] ?? null;
            if ($visitId === null) { $skipped++; $bar->advance(); continue; }

            [$odSph, $odCyl, $odAxis] = $this->parseAutoref($r['ocular_dextra_autoref'] ?? null);
            [$osSph, $osCyl, $osAxis] = $this->parseAutoref($r['ocular_sinistra_autoref'] ?? null);

            $raw = array_filter([
                'autoref_od' => $this->clean($r['ocular_dextra_autoref'] ?? null),
                'autoref_os' => $this->clean($r['ocular_sinistra_autoref'] ?? null),
                'visus_od'   => $this->clean($r['ocular_dextra_visus'] ?? null),
                'visus_os'   => $this->clean($r['ocular_sinistra_visus'] ?? null),
                '_source'    => 'pemeriksaan_ro',
            ], fn ($v) => $v !== null);

            $data = [
                'visit_id'        => $visitId,
                'examined_by_id'  => $this->employeeByLegacy[$r['pengguna_uuid']] ?? null,
                'examination_date'=> $this->parseTimestamp($r['tanggal'] ?? null, $r['waktu'] ?? null),
                'autoref_od_sph'  => $odSph, 'autoref_od_cyl' => $odCyl, 'autoref_od_axis' => $odAxis,
                'autoref_os_sph'  => $osSph, 'autoref_os_cyl' => $osCyl, 'autoref_os_axis' => $osAxis,
                'keratometri1_od' => $this->num($r['ocular_dextra_keratometri_k1'] ?? null),
                'keratometri2_od' => $this->num($r['ocular_dextra_keratometri_k2'] ?? null),
                'keratometri1_os' => $this->num($r['ocular_sinistra_keratometri_k1'] ?? null),
                'keratometri2_os' => $this->num($r['ocular_sinistra_keratometri_k2'] ?? null),
                'visus_awal_od'   => $this->truncate($this->clean($r['ocular_dextra_visus'] ?? null), 20),
                'visus_akhir_od'  => $this->truncate($this->pickBcva($r, 'dextra'), 20),
                'pinhole_od'      => $this->truncate($this->clean($r['ocular_dextra_pinhole'] ?? null), 20),
                'add_power_od'    => $this->num($r['ocular_dextra_add'] ?? null),
                'visus_awal_os'   => $this->truncate($this->clean($r['ocular_sinistra_visus'] ?? null), 20),
                'visus_akhir_os'  => $this->truncate($this->pickBcva($r, 'sinistra'), 20),
                'pinhole_os'      => $this->truncate($this->clean($r['ocular_sinistra_pinhole'] ?? null), 20),
                'add_power_os'    => $this->num($r['ocular_sinistra_add'] ?? null),
                'iop_od'          => $this->num($r['ocular_dextra_tonometri'] ?? null),
                'iop_os'          => $this->num($r['ocular_sinistra_tonometri'] ?? null),
                'pd_distance'     => $this->num($r['ocular_dextra_pd'] ?? null),
                'old_glasses_od_sph' => $this->num($r['ocular_dextra_kacamata_lama_sph'] ?? null),
                'old_glasses_od_cyl' => $this->num($r['ocular_dextra_kacamata_lama_cyl'] ?? null),
                'old_glasses_add_od' => $this->num($r['ocular_dextra_kacamata_lama_addisi'] ?? null),
                'old_glasses_os_sph' => $this->num($r['ocular_sinistra_kacamata_lama_sph'] ?? null),
                'old_glasses_os_cyl' => $this->num($r['ocular_sinistra_kacamata_lama_cyl'] ?? null),
                'old_glasses_add_os' => $this->num($r['ocular_sinistra_kacamata_lama_addisi'] ?? null),
                'raw_data'        => $raw ?: null,
            ];

            if (! $this->dry) {
                RefractionRecord::updateOrCreate(['legacy_uuid' => $r['uuid']], $data);
            }
            $created++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->line("  dibuat/diupdate: {$created} · dilewati (visit orphan): {$skipped}");
    }

    private function pickBcva(array $r, string $eye): ?string
    {
        $b2 = $this->clean($r["ocular_{$eye}_bcva2"] ?? null);
        return $b2 ?? $this->clean($r["ocular_{$eye}_bcva1"] ?? null);
    }

    /**
     * Parse string autoref Prima Vision → [sph, cyl, axis].
     * Toleran: koma desimal, spasi acak, notasi ×100 (S-275 = -2.75), silinder tanpa S,
     * literal "error" → semua NULL. Hasil di-clamp ke jangkauan decimal(5,2) & axis 0..180.
     */
    private function parseAutoref(?string $raw): array
    {
        $s = trim((string) $raw);
        if ($s === '' || $s === '-' || $s === '0.0' || $s === '0') return [null, null, null];
        if (preg_match('/error/i', $s)) return [null, null, null];

        $norm = str_replace(',', '.', $s);
        $norm = preg_replace('/\s+/', ' ', $norm);

        $sph = $cyl = $axis = null;

        if (preg_match('/S\s*([+-]?\s*[0-9]+(?:\.[0-9]+)?)/i', $norm, $m)) {
            $sph = $this->scaleDiopter($m[1]);
        }
        if (preg_match('/C\s*([+-]?\s*[0-9]+(?:\.[0-9]+)?)/i', $norm, $m)) {
            $cyl = $this->scaleDiopter($m[1]);
        }
        if (preg_match('/X\s*([0-9]{1,3})/i', $norm, $m)) {
            $a = (int) $m[1];
            $axis = ($a >= 0 && $a <= 180) ? $a : null;
        }
        return [$sph, $cyl, $axis];
    }

    /** "−275" → −2.75 (notasi ×100); "−1.25" tetap. Clamp ±99.99. */
    private function scaleDiopter(string $v): ?float
    {
        $v = str_replace(' ', '', $v);
        if (! is_numeric($v)) return null;
        $f = (float) $v;
        if (abs($f) > 30) $f = $f / 100;       // notasi tanpa titik
        if (abs($f) > 99.99) return null;       // di luar decimal(5,2)
        return round($f, 2);
    }

    // ──────────────────────────────────────────────────────────── dokter

    private function migrateDokter(): void
    {
        $this->newLine();
        $this->info('▶ doctor_examinations (pemeriksaan_dokter + icdten) — 1 terbaru per registrasi');
        $best = $this->dedupByLatest('pemeriksaan_dokter', 'registrasi_uuid');
        $icd = $this->loadIcdSecondary(); // pemeriksaan_dokter_uuid => [kode,...]
        // soap_objective dirakit dari refraksi (meniru objectiveText DokterView live:
        // Visus/IOP/Rx). Map registrasi_uuid => teks "O".
        $objektifByReg = $this->buildObjektifFromRefraksi();

        $created = 0; $skipped = 0; $n = 0;
        $bar = $this->output->createProgressBar(count($best));
        foreach ($best as $r) {
            if ($this->limit && $n >= $this->limit) { $bar->finish(); break; }
            $n++;

            $visitId = $this->visitByLegacy[$r['registrasi_uuid']] ?? null;
            if ($visitId === null) { $skipped++; $bar->advance(); continue; }

            // pemeriksaan_diagnosa_kode di sumber = UUID master (BUKAN ICD-10) → diabaikan.
            // Kode ICD asli hanya ada di icdten: yang pertama → diagnosis_utama, sisanya → sekunder.
            $icdList = $icd[$r['uuid']] ?? [];
            $utama = $icdList[0] ?? null;
            $sekunder = array_slice($icdList, 1);

            // Nama diagnosa & tindakan teks tetap disimpan agar konteks klinis tak hilang
            // (mayoritas pemeriksaan tanpa kode ICD/ICD-9 standar di sumber).
            $namaDiagnosa = $this->clean($r['pemeriksaan_diagnosa'] ?? null);
            $namaTindakan = $this->clean($r['pemeriksaan_tindakan'] ?? null);

            $data = [
                'visit_id'           => $visitId,
                'doctor_id'          => $this->employeeByLegacy[$r['pengguna_uuid']] ?? $this->resolveDoctorByName($r['nama_dokter'] ?? null),
                'anamnese'           => $this->clean($r['anamnese'] ?? null),
                'soap_objective'     => $objektifByReg[$r['registrasi_uuid']] ?? null,
                'soap_assessment'    => $namaDiagnosa,
                'soap_plan'          => $namaTindakan,
                'diagnosis_utama'    => $utama,
                'diagnosis_sekunder' => $sekunder ?: null,
                'tindakan_codes'     => $this->wrapTindakan($r['pemeriksaan_tindakan_kode'] ?? null),
                'planning'           => $this->mapPlanning($r['pilihan_plan'] ?? null),
            ];

            if (! $this->dry) {
                DoctorExamination::updateOrCreate(['legacy_uuid' => $r['uuid']], $data);
            }
            $created++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->line("  dibuat/diupdate: {$created} · dilewati (visit orphan): {$skipped}");
        $unmatched = array_sum($this->unresolvedDoctor);
        $this->line("  doctor_id by-nama: {$this->doctorByName} · tak ter-resolve: {$unmatched} (" . count($this->unresolvedDoctor) . ' nama unik)');
        if ($this->unresolvedDoctor) {
            arsort($this->unresolvedDoctor);
            foreach ($this->unresolvedDoctor as $nm => $c) {
                $this->line("    (dokter tak cocok) {$nm}: {$c}");
            }
        }
    }

    /**
     * Rakit teks "O" (Objective) SOAP dari refraksi sumber, meniru `objectiveText`
     * DokterView live (visus UCVA/BCVA + IOP + Rx autoref). Di app live, "O" disusun
     * otomatis di layar dokter dari data RO; data migrasi tak lewat layar itu, jadi
     * di sini dirakit langsung agar riwayat SOAP pasien lama tampil utuh.
     * Return: registrasi_uuid => teks "O" (atau tak ada key bila kosong).
     */
    private function buildObjektifFromRefraksi(): array
    {
        // Ambil 1 refraksi terbaru per registrasi (sama seperti yang dimigrasi).
        $best = $this->dedupByLatest('pemeriksaan_ro', 'registrasi_uuid');
        $map = [];
        foreach ($best as $r) {
            $lines = [];

            // Visus: UCVA (visus awal) & BCVA (bcva2 ?? bcva1) — hanya bila ada nilai.
            $ucvaOd = $this->clean($r['ocular_dextra_visus'] ?? null);
            $ucvaOs = $this->clean($r['ocular_sinistra_visus'] ?? null);
            if ($ucvaOd !== null || $ucvaOs !== null) {
                $lines[] = 'Visus UCVA: OD ' . ($ucvaOd ?? '-') . ' / OS ' . ($ucvaOs ?? '-');
            }
            $bcvaOd = $this->pickBcva($r, 'dextra');
            $bcvaOs = $this->pickBcva($r, 'sinistra');
            if ($bcvaOd !== null || $bcvaOs !== null) {
                $lines[] = 'Visus BCVA: OD ' . ($bcvaOd ?? '-') . ' / OS ' . ($bcvaOs ?? '-');
            }

            // IOP (tonometri) — hanya bila ada nilai.
            $iopOd = $this->clean($r['ocular_dextra_tonometri'] ?? null);
            $iopOs = $this->clean($r['ocular_sinistra_tonometri'] ?? null);
            if ($iopOd !== null || $iopOs !== null) {
                $lines[] = 'IOP: OD ' . ($iopOd ?? '-') . ' / OS ' . ($iopOs ?? '-') . ' mmHg';
            }

            // Rx (autoref) — string asli refraksi, hanya bila ada.
            $rxOd = $this->clean($r['ocular_dextra_autoref'] ?? null);
            $rxOs = $this->clean($r['ocular_sinistra_autoref'] ?? null);
            if ($rxOd !== null || $rxOs !== null) {
                $lines[] = 'Rx: OD ' . ($rxOd ?? '-') . ' | OS ' . ($rxOs ?? '-');
            }

            if ($lines) {
                $map[$r['registrasi_uuid']] = implode("\n", $lines);
            }
        }
        return $map;
    }

    /** Aggregate ICD-10 sekunder per pemeriksaan_dokter_uuid (hapus spasi). */
    private function loadIcdSecondary(): array
    {
        $map = [];
        foreach ($this->readCsv('pemeriksaan_dokter_icdten') as $r) {
            $kode = $this->normalizeIcd($r['kode_icdten'] ?? null);
            if ($kode === null) continue;
            $pid = $r['pemeriksaan_dokter_uuid'] ?? null;
            if (! $pid) continue;
            $map[$pid][] = $kode;
        }
        return $map;
    }

    private function normalizeIcd(?string $kode): ?string
    {
        $k = $this->clean($kode);
        if ($k === null) return null;
        return substr(str_replace(' ', '', $k), 0, 10);
    }

    /**
     * pemeriksaan_tindakan_kode di sumber = UUID master (BUKAN ICD-9 CM) & hanya 32
     * baris terisi → JANGAN simpan sebagai tindakan_codes (akan jadi UUID sampah).
     * Tindakan ICD-9 riil tidak tersedia di export ini → tindakan_codes selalu NULL.
     */
    private function wrapTindakan(?string $kode): ?array
    {
        return null;
    }

    private function mapPlanning(?string $plan): ?string
    {
        $p = mb_strtolower(trim((string) $plan));
        return match (true) {
            str_contains($p, 'pulang')  => 'PULANG_BEROBAT_JALAN',
            str_contains($p, 'operasi') => 'BEDAH',
            default                     => null, // kosong & 'rawat inap' → NULL
        };
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** Baca seluruh tabel, simpan baris terbaru (created_at max) per kunci. */
    private function dedupByLatest(string $table, string $key): array
    {
        $best = [];
        foreach ($this->readCsv($table) as $r) {
            $k = $r[$key] ?? null;
            if (! $k) continue;
            if (! isset($best[$k]) || ($r['created_at'] ?? '') > ($best[$k]['created_at'] ?? '')) {
                $best[$k] = $r;
            }
        }
        return $best;
    }

    /** Generator baris asosiatif dari CSV gzip (quote-aware, streaming). */
    private function readCsv(string $name): \Generator
    {
        $path = "{$this->csvDir}/{$name}.csv.gz";
        if (! is_file($path)) {
            throw new \RuntimeException("CSV tidak ditemukan: {$path}");
        }
        $fh = gzopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Gagal membuka gzip: {$path}");
        }
        try {
            $header = $this->gzFgetcsv($fh);
            if ($header === null) return;
            while (($row = $this->gzFgetcsv($fh)) !== null) {
                if (count($row) !== count($header)) {
                    // baris rusak / kolom tak sinkron → lewati
                    continue;
                }
                yield array_combine($header, $row);
            }
        } finally {
            gzclose($fh);
        }
    }

    /** fgetcsv manual untuk stream gzip (gzopen tak kompatibel dgn fgetcsv langsung secara andal). */
    private function gzFgetcsv($fh): ?array
    {
        $line = gzgets($fh);
        if ($line === false) return null;
        // Tangani field ber-newline di dalam quote: baca sampai jumlah quote genap.
        while (substr_count($line, '"') % 2 !== 0 && ! gzeof($fh)) {
            $next = gzgets($fh);
            if ($next === false) break;
            $line .= $next;
        }
        return str_getcsv(rtrim($line, "\r\n"), ',', '"', '\\');
    }

    /** Muat peta alias dokter (pipe-delimited: sumber|arumed) dari Docs/migrasi data/dokter-alias.csv. */
    private function loadDoctorAlias(): void
    {
        $path = dirname($this->csvDir) . '/dokter-alias.csv';
        if (! is_file($path)) {
            $this->warn("  (dokter-alias.csv tak ditemukan di {$path} — resolusi by-nama hanya andalkan normalisasi)");
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $i => $line) {
            if ($i === 0 && stripos($line, 'sumber') === 0) continue; // header
            $parts = explode('|', $line, 2);
            if (count($parts) !== 2) continue;
            $src = mb_strtolower(trim($parts[0]));
            $dst = mb_strtolower(trim($parts[1]));
            if ($src !== '' && $dst !== '') $this->doctorAlias[$src] = $dst;
        }
    }

    /**
     * Normalisasi nama dokter untuk pencocokan fuzzy: lowercase, potong setelah koma
     * pertama (buang gelar belakang), titik/kurung → spasi, buang token gelar depan.
     */
    private function normName(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        if ($s === '' || $s === '-') return '';
        $s = explode(',', $s)[0];
        $s = preg_replace('/[.()]/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim((string) $s));
        $titles = ['dr', 'drg', 'prof', 'h', 'hj', 'ny', 'tn'];
        $parts = array_values(array_filter(explode(' ', $s), fn ($p) => $p !== '' && ! in_array($p, $titles, true)));
        return implode(' ', $parts);
    }

    /**
     * Resolusi employee id dari nama dokter sumber: alias eksklit → exact → fuzzy(norm).
     * Hitung yang ter-resolve & yang tidak (untuk laporan). Null bila kosong/tak cocok.
     */
    private function resolveDoctorByName(?string $src): ?string
    {
        $k = mb_strtolower(trim((string) $src));
        if ($k === '' || $k === '-') return null;
        $canon = $this->doctorAlias[$k] ?? null;
        $id = $canon !== null
            ? ($this->employeeByNameExact[$canon] ?? null)
            : ($this->employeeByNameExact[$k] ?? ($this->employeeByNameNorm[$this->normName($src)] ?? null));
        if ($id !== null) {
            $this->doctorByName++;
        } else {
            $nm = trim((string) $src);
            $this->unresolvedDoctor[$nm] = ($this->unresolvedDoctor[$nm] ?? 0) + 1;
        }
        return $id;
    }

    /** '-' / '' / NULL → null; else trim. */
    private function clean(?string $v): ?string
    {
        if ($v === null) return null;
        $v = trim($v);
        return ($v === '' || $v === '-' || strtoupper($v) === 'NULL') ? null : $v;
    }

    /**
     * Parse ke float untuk kolom decimal(5,2) (maks ±999.99). Data refraksi/IOP/
     * keratometri kotor bisa berisi angka liar → di luar jangkauan dikembalikan NULL
     * (bukan dipotong) agar tak menyimpan nilai keliru. String asli sudah di raw_data.
     */
    private function num(?string $v): ?float
    {
        $v = $this->clean($v);
        if ($v === null) return null;
        $v = str_replace(',', '.', $v);
        // ambil token numerik pertama (buang satuan/teks seperti "14 mmHg", "40.5 D")
        if (! preg_match('/-?[0-9]+(?:\.[0-9]+)?/', $v, $m)) return null;
        $f = (float) $m[0];
        return abs($f) > 999.99 ? null : round($f, 2);
    }

    private function truncate(?string $v, int $len): ?string
    {
        return $v === null ? null : mb_substr($v, 0, $len);
    }

    private function parseTimestamp(?string $date, ?string $time): ?string
    {
        $d = $this->parseDob($date);
        if ($d === null) return null;
        $t = $this->clean($time) ?? '00:00';
        try { return Carbon::parse("{$d} {$t}")->toDateTimeString(); }
        catch (\Throwable) { return "{$d} 00:00:00"; }
    }
}
