<?php

namespace App\Services;

use App\Models\DoctorSchedule;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class JadwalDokterService
{
    // =========================================================================
    // LIST
    // =========================================================================

    /**
     * Semua dokter beserta jadwal mingguan mereka.
     * Response dikelompokkan per employee agar frontend bisa render per-dokter.
     */
    public function getAll(): array
    {
        $employees = Employee::whereHas('user.role', fn ($q) => $q->where('name', 'dokter'))
            ->with(['doctorSchedules' => fn ($q) => $q->orderBy('day_of_week')])
            ->orderBy('name')
            ->get();

        return $employees->map(fn ($emp) => $this->formatEmployee($emp))->values()->toArray();
    }

    /**
     * Dokter yang jadwalnya aktif hari ini (hari sesuai Carbon ISO 1-7).
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
            'queue_prefix' => $s->queuePrefix(),
            'start_time'   => $s->start_time,
            'end_time'     => $s->end_time,
        ])->values()->toArray();
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
     * Validasi: satu dokter tidak boleh punya 2 jadwal di hari yang sama.
     */
    public function create(array $data): DoctorSchedule
    {
        $this->assertNoDuplicate($data['employee_id'], $data['day_of_week']);

        return DoctorSchedule::create([
            'employee_id' => $data['employee_id'],
            'day_of_week' => $data['day_of_week'],
            'start_time'  => $data['start_time'],
            'end_time'    => $data['end_time'],
            'room'        => $data['room'] ?? null,
            'poliklinik'  => $data['poliklinik'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
        ]);
    }

    // =========================================================================
    // UPDATE
    // =========================================================================

    public function update(string $id, array $data): DoctorSchedule
    {
        $schedule = DoctorSchedule::findOrFail($id);

        // Cek duplikat hanya jika hari atau dokter berubah
        $newDow = $data['day_of_week'] ?? $schedule->day_of_week;
        $newEmp = $data['employee_id'] ?? $schedule->employee_id;

        if ($newDow !== $schedule->day_of_week || $newEmp !== $schedule->employee_id) {
            $this->assertNoDuplicate($newEmp, $newDow, excludeId: $id);
        }

        $schedule->update(array_filter([
            'employee_id' => $data['employee_id'] ?? null,
            'day_of_week' => $data['day_of_week'] ?? null,
            'start_time'  => $data['start_time']  ?? null,
            'end_time'    => $data['end_time']     ?? null,
            'room'        => $data['room']         ?? null,
            'poliklinik'  => $data['poliklinik']   ?? null,
            'is_active'   => isset($data['is_active']) ? $data['is_active'] : null,
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
    // PRIVATE
    // =========================================================================

    private function assertNoDuplicate(string $employeeId, int $dayOfWeek, ?string $excludeId = null): void
    {
        $query = DoctorSchedule::where('employee_id', $employeeId)
            ->where('day_of_week', $dayOfWeek);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            $day = DoctorSchedule::DAY_LABELS[$dayOfWeek] ?? "Hari ke-{$dayOfWeek}";
            throw new \Exception("Dokter sudah memiliki jadwal pada hari {$day}.", 422);
        }
    }

    private function formatEmployee(Employee $emp): array
    {
        return [
            'employee_id'   => $emp->id,
            'nama_dokter'   => $emp->name,
            'jadwal'        => $emp->doctorSchedules->map(fn ($s) => [
                'id'          => $s->id,
                'day_of_week' => $s->day_of_week,
                'hari'        => DoctorSchedule::DAY_LABELS[$s->day_of_week] ?? '?',
                'start_time'  => $s->start_time,
                'end_time'    => $s->end_time,
                'room'        => $s->room,
                'poliklinik'  => $s->poliklinik,
                'queue_prefix' => $s->queuePrefix(),
                'is_active'   => $s->is_active,
            ])->values(),
        ];
    }
}
