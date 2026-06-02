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
            ->aktifHariIni()
            ->orderBy('room')
            ->get();

        return $schedules->map(fn ($s) => [
            'id'           => $s->id,
            'employee_id'  => $s->employee_id,
            'nama_dokter'  => $s->employee?->name ?? '—',
            'poliklinik'   => $s->poliklinik,
            'room'         => $s->room,
            'service_type' => $s->service_type,
            'queue_prefix' => $s->queuePrefix(),
            'start_time'   => $s->start_time,
            'end_time'     => $s->end_time,
        ])->values()->toArray();
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

    /** Isi file template CSV (header + 2 baris contoh). */
    public function getCsvTemplate(): string
    {
        $rows = [
            self::CSV_HEADER,
            ['dr. Contoh Aulia', 'BPJS',      'GLA', 'Poliklinik Glaukoma',  'Senin', '08:00', '12:00', '1'],
            ['dr. Contoh Aulia', 'EKSEKUTIF', 'EKS', 'Poliklinik Eksekutif', 'Senin', '13:00', '16:00', '1'],
        ];

        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
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
                $s->start_time,
                $s->end_time,
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

        // Header → index kolom (toleran urutan & spasi).
        $header = fgetcsv($fh, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($fh);
            throw new \Exception('File CSV kosong.', 422);
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

                // Validasi jam.
                if (! $this->isTime($jamMulai) || ! $this->isTime($jamSelesai)) {
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

    private function isTime(string $v): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v);
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
            'employee_id'   => $emp->id,
            'nama_dokter'   => $emp->name,
            'jadwal'        => $emp->doctorSchedules->map(fn ($s) => [
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
