<?php

namespace App\Services;

use App\Models\DoctorSchedule;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class JadwalDokterService
{
    // Header kolom CSV import/export (urutan menentukan kolom file).
    public const CSV_HEADER = [
        'nama_dokter', 'jenis', 'kode_poli', 'nama_poli',
        'hari', 'jam_mulai', 'jam_selesai', 'ruang',
    ];

    /** Ambang "hampir penuh" — sisa kuota (JKN/non-JKN) <= ini → peringatan di Admisi. */
    private const QUOTA_RISK = 3;

    // =========================================================================
    // LIST
    // =========================================================================

    /**
     * Semua dokter beserta jadwal mereka untuk satu minggu (+ filter layanan).
     * Response dikelompokkan per employee agar frontend bisa render per-dokter.
     *
     * @param  string|null  $weekStart    Senin minggu yang diminta (default: minggu ini)
     * @param  string|null  $serviceType  BPJS|EKSEKUTIF (default: semua)
     */
    public function getAll(?string $weekStart = null, ?string $serviceType = null): array
    {
        $weekStart = $weekStart ? DoctorSchedule::weekStartFor($weekStart) : DoctorSchedule::currentWeekStart();

        $employees = Employee::whereHas('user.role', fn ($q) => $q->where('name', 'dokter'))
            ->where('doctor_type', Employee::DT_SPESIALIS_MATA) // hanya Dokter Spesialis Mata yang dijadwalkan
            ->with(['doctorSchedules' => function ($q) use ($weekStart, $serviceType) {
                $q->where('week_start', $weekStart)->orderBy('day_of_week');
                if ($serviceType) {
                    $q->where('service_type', $serviceType);
                }
            }])
            ->orderBy('name')
            ->get();

        return $employees->map(fn ($emp) => $this->formatEmployee($emp))->values()->toArray();
    }

    /**
     * Dokter yang jadwalnya aktif hari ini (minggu berjalan + hari sesuai ISO 1-7).
     * Dipakai oleh: Admisi dropdown + Antrean TV panel.
     */
    public function getAktifHariIni(): array
    {
        $schedules = DoctorSchedule::with('employee')
            ->whereHas('employee', fn ($q) => $q->where('doctor_type', Employee::DT_SPESIALIS_MATA))
            ->aktifHariIni()
            ->orderBy('room')
            ->get();

        $kuotaService = app(AntreanKuotaService::class);
        $today        = today()->toDateString();

        return $schedules->map(function ($s) use ($kuotaService, $today) {
            // Sisa kuota (peringatan "hampir penuh" saat petugas Admisi pilih dokter).
            $sisaJkn = $sisaNonJkn = null;
            $hampirPenuh = false;
            if ($s->poli_code) {
                $ring        = $kuotaService->ringkasanKuota($s->poli_code, $s->employee_id, $today);
                $sisaJkn     = (int) $ring['sisakuotajkn'];
                $sisaNonJkn  = (int) $ring['sisakuotanonjkn'];
                // "Hampir penuh" hanya bila kuota penjamin itu memang ditetapkan (>0).
                // Jadwal dengan kuota 0 (mis. dokter tak melayani JKN) → sisa = 0, TAPI
                // itu bukan "hampir penuh" — tanpa guard ini dropdown Admisi/TV memunculkan
                // "⚠ Hampir penuh (sisa 0)" palsu. (Selaras scheduleRiskFor di Triase.)
                $jknRisk     = (int) $ring['kuotajkn'] > 0 && $sisaJkn <= self::QUOTA_RISK;
                $nonjknRisk  = (int) $ring['kuotanonjkn'] > 0 && $sisaNonJkn <= self::QUOTA_RISK;
                $hampirPenuh = $jknRisk || $nonjknRisk;
            }

            return [
                'id'           => $s->id,
                'employee_id'  => $s->employee_id,
                'nama_dokter'  => $s->employee?->name ?? '—',
                'poliklinik'   => $s->poliklinik,
                'room'         => $s->room,
                'service_type' => $s->service_type,
                'queue_prefix' => $s->queuePrefix(),
                'start_time'   => $s->start_time,
                'end_time'     => $s->end_time,
                'sisa_jkn'     => $sisaJkn,
                'sisa_nonjkn'  => $sisaNonJkn,
                'hampir_penuh' => $hampirPenuh,
            ];
        })->values()->toArray();
    }

    /**
     * Daftar minggu (week_start) yang sudah punya jadwal, untuk selector minggu.
     * Selalu sertakan minggu ini & minggu depan walau belum ada datanya.
     */
    public function availableWeeks(): array
    {
        $current = DoctorSchedule::currentWeekStart();
        $next    = \Carbon\Carbon::parse($current)->addWeek()->toDateString();

        $weeks = DoctorSchedule::query()
            ->select('week_start')
            ->distinct()
            ->orderBy('week_start')
            ->pluck('week_start')
            ->map(fn ($w) => \Carbon\Carbon::parse($w)->toDateString())
            ->all();

        $weeks = array_values(array_unique(array_merge($weeks, [$current, $next])));
        sort($weeks);

        return array_map(fn ($w) => [
            'week_start' => $w,
            'week_end'   => \Carbon\Carbon::parse($w)->addDays(6)->toDateString(),
            'is_current' => $w === $current,
        ], $weeks);
    }

    // =========================================================================
    // DETAIL
    // =========================================================================

    public function getById(string $id): DoctorSchedule
    {
        return DoctorSchedule::with('employee')->findOrFail($id);
    }

    // =========================================================================
    // CREATE
    // =========================================================================

    /**
     * Buat jadwal untuk satu hari.
     * Validasi: tidak boleh duplikat (dokter, hari, jenis layanan, minggu).
     */
    public function create(array $data): DoctorSchedule
    {
        $weekStart   = DoctorSchedule::weekStartFor($data['week_start'] ?? null);
        $serviceType = $this->normalizeServiceType($data['service_type'] ?? null);

        $this->assertNoDuplicate($data['employee_id'], $data['day_of_week'], $serviceType, $weekStart);

        return DoctorSchedule::create([
            'employee_id'  => $data['employee_id'],
            'day_of_week'  => $data['day_of_week'],
            'start_time'   => $data['start_time'],
            'end_time'     => $data['end_time'],
            'room'         => $data['room'] ?? null,
            'poliklinik'   => $data['poliklinik'] ?? null,
            'poli_code'    => $data['poli_code'] ?? null,
            'service_type' => $serviceType,
            'week_start'   => $weekStart,
            'is_active'    => $data['is_active'] ?? true,
        ]);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    public function update(string $id, array $data): DoctorSchedule
    {
        $schedule = DoctorSchedule::findOrFail($id);

        // Hitung nilai baru untuk cek duplikat.
        $newDow  = $data['day_of_week']  ?? $schedule->day_of_week;
        $newEmp  = $data['employee_id']  ?? $schedule->employee_id;
        $newSvc  = isset($data['service_type']) ? $this->normalizeServiceType($data['service_type']) : $schedule->service_type;
        $newWeek = isset($data['week_start']) ? DoctorSchedule::weekStartFor($data['week_start']) : $schedule->week_start->toDateString();

        $changed = $newDow !== $schedule->day_of_week
            || $newEmp !== $schedule->employee_id
            || $newSvc !== $schedule->service_type
            || $newWeek !== $schedule->week_start->toDateString();

        if ($changed) {
            $this->assertNoDuplicate($newEmp, $newDow, $newSvc, $newWeek, excludeId: $id);
        }

        $schedule->update(array_filter([
            'employee_id'  => $data['employee_id']  ?? null,
            'day_of_week'  => $data['day_of_week']  ?? null,
            'start_time'   => $data['start_time']   ?? null,
            'end_time'     => $data['end_time']     ?? null,
            'room'         => $data['room']         ?? null,
            'poliklinik'   => $data['poliklinik']   ?? null,
            'poli_code'    => $data['poli_code']    ?? null,
            'service_type' => isset($data['service_type']) ? $newSvc : null,
            'week_start'   => isset($data['week_start']) ? $newWeek : null,
            'is_active'    => isset($data['is_active']) ? $data['is_active'] : null,
        ], fn ($v) => $v !== null));

        return $schedule->fresh('employee');
    }

    // =========================================================================
    // DELETE
    // =========================================================================

    public function delete(string $id): void
    {
        DoctorSchedule::findOrFail($id)->delete();
    }

    // =========================================================================
    // TOGGLE AKTIF
    // =========================================================================

    public function toggleAktif(string $id): DoctorSchedule
    {
        $schedule = DoctorSchedule::findOrFail($id);
        $schedule->update(['is_active' => ! $schedule->is_active]);
        return $schedule->fresh('employee');
    }

    // =========================================================================
    // SALIN KE MINGGU DEPAN
    // =========================================================================

    /**
     * Salin semua jadwal dari $weekStart ke minggu berikutnya (+7 hari).
     * Anti-duplikat: baris yang sudah ada di minggu tujuan dilewati.
     *
     * @return array{copied:int, skipped:int, target_week:string}
     */
    public function copyToNextWeek(?string $weekStart = null): array
    {
        $source = $weekStart ? DoctorSchedule::weekStartFor($weekStart) : DoctorSchedule::currentWeekStart();
        $target = \Carbon\Carbon::parse($source)->addWeek()->toDateString();

        $rows = DoctorSchedule::forWeek($source)->get();

        $copied = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $target, &$copied, &$skipped) {
            foreach ($rows as $row) {
                $exists = DoctorSchedule::where('employee_id', $row->employee_id)
                    ->where('day_of_week', $row->day_of_week)
                    ->where('service_type', $row->service_type)
                    ->where('week_start', $target)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                DoctorSchedule::create([
                    'employee_id'  => $row->employee_id,
                    'day_of_week'  => $row->day_of_week,
                    'start_time'   => $row->start_time,
                    'end_time'     => $row->end_time,
                    'room'         => $row->room,
                    'poliklinik'   => $row->poliklinik,
                    'poli_code'    => $row->poli_code,
                    'service_type' => $row->service_type,
                    'week_start'   => $target,
                    'is_active'    => $row->is_active,
                ]);
                $copied++;
            }
        });

        return ['copied' => $copied, 'skipped' => $skipped, 'target_week' => $target];
    }

    // =========================================================================
    // CSV TEMPLATE & IMPORT
    // =========================================================================

    /** Petunjuk pengisian template (baris "#" — diabaikan saat impor, ikut ke CSV & Excel). */
    private const TEMPLATE_RULES = [
        '# ====================== PETUNJUK PENGISIAN JADWAL DOKTER ======================',
        '# Baris berawalan "#" hanya petunjuk dan DIABAIKAN saat impor (boleh dihapus).',
        '# Kolom WAJIB  : nama_dokter, jenis, hari, jam_mulai, jam_selesai',
        '# Kolom OPSIONAL: kode_poli, nama_poli, ruang',
        '# -----------------------------------------------------------------------------',
        '# nama_dokter  : harus PERSIS sama dengan dokter terdaftar (besar/kecil & spasi diabaikan).',
        '# jenis        : BPJS atau EKSEKUTIF (sinonim EKS / NON-BPJS dianggap EKSEKUTIF).',
        '# hari         : Senin..Minggu (boleh nama Inggris atau angka 1-7; 1=Senin, 7=Minggu).',
        '# jam_mulai    : format 24 jam HH:MM (mis. 08:00).',
        '# jam_selesai  : format HH:MM dan HARUS lebih besar dari jam_mulai.',
        '# kode_poli    : kode singkat poli untuk NOMOR ANTREAN (mis. GLA). Prefix antrean = kode_poli + ruang (GLA1).',
        '#                Untuk pasien BPJS, kode ini WAJIB dipetakan ke kode poli BPJS di',
        '#                menu Pemetaan BPJS -> Pemetaan Poli. INI BUKAN kode dokter/DPJP.',
        '# nama_poli    : nama poliklinik yang tampil ke pasien (mis. Poliklinik Glaukoma).',
        '# ruang        : nomor/identitas ruang (mis. 1).',
        '# CATATAN BPJS : kode DPJP dokter diatur TERPISAH (Pemetaan BPJS -> Kode DPJP), bukan lewat file ini.',
        '# Duplikat (dokter + hari + jenis pada minggu yang sama) otomatis dilewati saat impor.',
        '# =============================================================================',
    ];

    /** Isi file template CSV: blok petunjuk (#) + header + 2 baris contoh. */
    public function getCsvTemplate(): string
    {
        $examples = [
            self::CSV_HEADER,
            ['dr. Contoh Aulia', 'BPJS',      'GLA', 'Poliklinik Glaukoma',  'Senin', '08:00', '12:00', '1'],
            ['dr. Contoh Aulia', 'EKSEKUTIF', 'EKS', 'Poliklinik Eksekutif', 'Senin', '13:00', '16:00', '1'],
        ];

        $fh = fopen('php://temp', 'r+');
        // Petunjuk ditulis sebagai teks mentah (BUKAN fputcsv) agar tetap diawali "#"
        // dan terdeteksi sebagai komentar (fputcsv akan membungkus tanda kutip).
        foreach (self::TEMPLATE_RULES as $line) {
            fwrite($fh, $line . "\n");
        }
        foreach ($examples as $r) {
            fputcsv($fh, $r, ',', '"', '\\');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    /**
     * Export jadwal aktual ke CSV (format identik template → bisa di-import balik).
     * Filter minggu (default minggu berjalan) + jenis layanan opsional.
     */
    public function getCsvExport(?string $weekStart = null, ?string $serviceType = null): string
    {
        $week = $weekStart ? DoctorSchedule::weekStartFor($weekStart) : DoctorSchedule::currentWeekStart();
        $svc  = $serviceType ? $this->normalizeServiceType($serviceType, throwOnInvalid: false) : null;

        $rows = DoctorSchedule::with('employee')
            ->where('week_start', $week)
            ->when($svc, fn ($q) => $q->where('service_type', $svc))
            ->orderBy('day_of_week')
            ->get()
            ->sortBy(fn ($s) => $s->employee?->name)
            ->values();

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, self::CSV_HEADER, ',', '"', '\\');
        foreach ($rows as $s) {
            fputcsv($fh, [
                $s->employee?->name ?? '',
                $s->service_type,
                $s->poli_code ?? '',
                $s->poliklinik ?? '',
                DoctorSchedule::DAY_LABELS[$s->day_of_week] ?? '',
                $this->hhmm($s->start_time),   // kolom TIME → 'HH:MM:SS'; potong ke 'HH:MM' agar identik template + bisa di-import balik.
                $this->hhmm($s->end_time),
                $s->room ?? '',
            ], ',', '"', '\\');
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    /**
     * Import jadwal dari file CSV ke minggu tertentu.
     * Lookup dokter by nama (case-insensitive, trim). Baris invalid dilaporkan,
     * baris valid tetap diproses (partial success). Duplikat di-skip.
     *
     * @return array{imported:int, skipped:int, errors:array<int,string>, target_week:string}
     */
    public function importCsv(string $path, ?string $weekStart = null): array
    {
        $target = $weekStart ? DoctorSchedule::weekStartFor($weekStart) : DoctorSchedule::currentWeekStart();

        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \Exception('Gagal membaca file CSV.', 422);
        }

        // Header → index kolom (toleran urutan & spasi). Lewati dulu baris petunjuk
        // (#) & baris kosong di atas header — template menyertakan blok petunjuk.
        $header = false;
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($this->isBlankRow($row) || str_starts_with(ltrim((string) ($row[0] ?? '')), '#')) {
                continue;
            }
            $header = $row;
            break;
        }
        if ($header === false) {
            fclose($fh);
            throw new \Exception('File CSV kosong / tidak ada baris header.', 422);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $idx = array_flip($header);

        foreach (['nama_dokter', 'jenis', 'hari', 'jam_mulai', 'jam_selesai'] as $req) {
            if (! array_key_exists($req, $idx)) {
                fclose($fh);
                throw new \Exception("Kolom wajib '{$req}' tidak ditemukan di header CSV.", 422);
            }
        }

        // Cache nama dokter → employee_id (semua dokter).
        $doctors = Employee::whereHas('user.role', fn ($q) => $q->where('name', 'dokter'))
            ->get(['id', 'name'])
            ->keyBy(fn ($e) => $this->normalizeName($e->name));

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $line     = 1; // header = baris 1

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                $line++;

                // Lewati baris kosong / komentar (# di kolom pertama).
                if ($this->isBlankRow($row) || str_starts_with(ltrim((string) ($row[0] ?? '')), '#')) {
                    continue;
                }

                $get = fn (string $key) => isset($idx[$key]) ? trim((string) ($row[$idx[$key]] ?? '')) : '';

                $namaDokter = $get('nama_dokter');
                $jenisRaw   = $get('jenis');
                $hariRaw    = $get('hari');
                $jamMulai   = $get('jam_mulai');
                $jamSelesai = $get('jam_selesai');

                // Resolve dokter.
                $emp = $doctors->get($this->normalizeName($namaDokter));
                if (! $emp) {
                    $errors[] = "Baris {$line}: dokter \"{$namaDokter}\" tidak ditemukan.";
                    $skipped++;
                    continue;
                }

                // Resolve hari & jenis.
                $dow = $this->parseDay($hariRaw);
                if ($dow === null) {
                    $errors[] = "Baris {$line}: hari \"{$hariRaw}\" tidak dikenali.";
                    $skipped++;
                    continue;
                }
                $service = $this->normalizeServiceType($jenisRaw, throwOnInvalid: false);
                if ($service === null) {
                    $errors[] = "Baris {$line}: jenis \"{$jenisRaw}\" harus BPJS atau EKSEKUTIF.";
                    $skipped++;
                    continue;
                }

                // Validasi + normalisasi jam ke 'HH:MM' (terima 'H:MM' / 'HH:MM' / 'HH:MM:SS'
                // dari export kolom TIME maupun Excel/LibreOffice).
                $jamMulai   = $this->normalizeTime($jamMulai);
                $jamSelesai = $this->normalizeTime($jamSelesai);
                if ($jamMulai === '' || $jamSelesai === '') {
                    $errors[] = "Baris {$line}: format jam harus HH:MM (mis. 08:00).";
                    $skipped++;
                    continue;
                }
                if ($jamSelesai <= $jamMulai) {
                    $errors[] = "Baris {$line}: jam selesai harus setelah jam mulai.";
                    $skipped++;
                    continue;
                }

                // Anti-duplikat (dokter, hari, jenis, minggu).
                $dup = DoctorSchedule::where('employee_id', $emp->id)
                    ->where('day_of_week', $dow)
                    ->where('service_type', $service)
                    ->where('week_start', $target)
                    ->exists();
                if ($dup) {
                    $skipped++;
                    continue;
                }

                DoctorSchedule::create([
                    'employee_id'  => $emp->id,
                    'day_of_week'  => $dow,
                    'start_time'   => $jamMulai,
                    'end_time'     => $jamSelesai,
                    'room'         => $get('ruang') ?: null,
                    'poliklinik'   => $get('nama_poli') ?: null,
                    'poli_code'    => $get('kode_poli') ?: null,
                    'service_type' => $service,
                    'week_start'   => $target,
                    'is_active'    => true,
                ]);
                $imported++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($fh);
            throw new \Exception('Import gagal: ' . $e->getMessage(), 422);
        }

        fclose($fh);

        return [
            'imported'    => $imported,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'target_week' => $target,
        ];
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function assertNoDuplicate(
        string $employeeId,
        int $dayOfWeek,
        string $serviceType,
        string $weekStart,
        ?string $excludeId = null
    ): void {
        $query = DoctorSchedule::where('employee_id', $employeeId)
            ->where('day_of_week', $dayOfWeek)
            ->where('service_type', $serviceType)
            ->where('week_start', $weekStart);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            $day   = DoctorSchedule::DAY_LABELS[$dayOfWeek] ?? "Hari ke-{$dayOfWeek}";
            $label = $serviceType === DoctorSchedule::SERVICE_BPJS ? 'BPJS' : 'Eksekutif';
            throw new \Exception("Dokter sudah memiliki jadwal {$label} pada hari {$day} di minggu ini.", 422);
        }
    }

    private function normalizeServiceType(?string $raw, bool $throwOnInvalid = true): ?string
    {
        $val = strtoupper(trim((string) $raw));

        if ($val === '' && $throwOnInvalid) {
            return DoctorSchedule::SERVICE_BPJS; // default
        }

        if (in_array($val, DoctorSchedule::SERVICE_TYPES, true)) {
            return $val;
        }

        // Sinonim umum.
        if (in_array($val, ['EKS', 'EKSEKUTIF', 'NON BPJS', 'NON-BPJS', 'EXECUTIVE'], true)) {
            return DoctorSchedule::SERVICE_EKSEKUTIF;
        }

        if ($throwOnInvalid) {
            return DoctorSchedule::SERVICE_BPJS;
        }
        return null;
    }

    private function parseDay(string $raw): ?int
    {
        $v = strtolower(trim($raw));
        if ($v === '') {
            return null;
        }

        // Numerik 1-7.
        if (is_numeric($v)) {
            $n = (int) $v;
            return ($n >= 1 && $n <= 7) ? $n : null;
        }

        $map = [
            'senin' => 1, 'monday' => 1, 'mon' => 1,
            'selasa' => 2, 'tuesday' => 2, 'tue' => 2,
            'rabu' => 3, 'wednesday' => 3, 'wed' => 3,
            'kamis' => 4, 'thursday' => 4, 'thu' => 4,
            'jumat' => 5, "jum'at" => 5, 'friday' => 5, 'fri' => 5,
            'sabtu' => 6, 'saturday' => 6, 'sat' => 6,
            'minggu' => 7, 'ahad' => 7, 'sunday' => 7, 'sun' => 7,
        ];

        return $map[$v] ?? null;
    }

    /**
     * Normalisasi string jam ke 'HH:MM'. Menerima 'H:MM', 'HH:MM', 'HH:MM:SS'
     * (kolom TIME di-export sbg 'HH:MM:SS', Excel kerap menambah detik).
     * Return '' bila tidak valid.
     */
    private function normalizeTime(string $v): string
    {
        $v = trim($v);
        if (! preg_match('/^(\d{1,2}):([0-5]\d)(?::[0-5]\d)?$/', $v, $m)) {
            return '';
        }
        $h = (int) $m[1];
        if ($h > 23) {
            return '';
        }

        return str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':' . $m[2];
    }

    /** Potong nilai TIME ('HH:MM:SS') → 'HH:MM' untuk output CSV/Excel. */
    private function hhmm(?string $t): string
    {
        return $t ? substr($t, 0, 5) : '';
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($name)));
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }

    private function formatEmployee(Employee $emp): array
    {
        return [
            'employee_id'    => $emp->id,
            'nama_dokter'    => $emp->name,
            'bpjs_dpjp_code' => $emp->bpjs_dpjp_code, // status kode DPJP BPJS (utk badge di FE)
            'jadwal'         => $emp->doctorSchedules->map(fn ($s) => [
                'id'           => $s->id,
                'day_of_week'  => $s->day_of_week,
                'hari'         => DoctorSchedule::DAY_LABELS[$s->day_of_week] ?? '?',
                'start_time'   => $s->start_time,
                'end_time'     => $s->end_time,
                'room'         => $s->room,
                'poliklinik'   => $s->poliklinik,
                'poli_code'    => $s->poli_code,
                'service_type' => $s->service_type,
                'week_start'   => $s->week_start?->toDateString(),
                'queue_prefix' => $s->queuePrefix(),
                'is_active'    => $s->is_active,
            ])->values(),
        ];
    }
}
