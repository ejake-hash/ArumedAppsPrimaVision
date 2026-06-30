<?php

namespace Tests\Feature\Ranap;

use App\Models\Patient;
use App\Models\Queue;
use App\Models\SurgeryPackage;
use App\Models\SurgerySchedule;
use App\Models\Visit;
use App\Services\BedahService;
use App\Services\QueueService;
use App\Services\RanapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Karakterisasi "Kirim ke Bedah" dari Rawat Inap.
 *
 * Bug (30 Jun 2026): mengirim pasien RANAP ke Bedah TANPA paket dulu tidak membuat
 * surgery_schedule (early-return karena komentar usang "kolom NOT NULL"). Padahal
 * papan Bedah menyaring keluar baris antrean BEDAH yang visit-nya tak punya
 * surgery_schedule (BedahService::getPatientQueue guard whereHas surgerySchedule) →
 * pasien "dikirim" tapi lenyap dari papan & operasi tak bisa dimulai.
 *
 * Fix: maybeCreateSurgerySchedule SELALU membuat jadwal (surgery_package_id nullable
 * sejak migrasi laser 2026_06_20_000001). Bedah menerima semua kondisi: tanpa paket
 * ATAU ada paket.
 */
class SendToBedahScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** Buat pasien RANAP dengan baris antrean RANAP aktif (prasyarat sendToBedah). */
    private function makeRanapVisit(): Visit
    {
        $patient = new Patient();
        $patient->forceFill(['name' => 'Pasien Ranap Uji'])->save();

        $visit = new Visit();
        $visit->forceFill([
            'patient_id'      => $patient->id,
            'visit_date'      => today()->toDateString(),
            'classification'  => 'RAWAT_INAP',
            'guarantor_type'  => 'UMUM',
            'jenis_pelayanan' => 'RANAP',
            'current_station' => Queue::STATION_RANAP,
        ])->save();

        // Baris RANAP hidup (gate "punya baris rawat inap aktif").
        app(QueueService::class)->enqueue($visit->id, Queue::STATION_RANAP);

        return $visit;
    }

    public function test_kirim_bedah_tanpa_paket_membuat_jadwal_dan_tampil_di_papan(): void
    {
        $visit = $this->makeRanapVisit();

        // Kirim ke Bedah TANPA paket (opsi default modal RANAP).
        app(RanapService::class)->sendToBedah($visit, null, []);

        $visit->refresh();

        // 1. Jadwal SELALU dibuat & tertaut ke visit.
        $this->assertNotNull($visit->surgery_schedule_id, 'visit.surgery_schedule_id wajib terisi');

        $schedule = SurgerySchedule::findOrFail($visit->surgery_schedule_id);
        $this->assertNull($schedule->surgery_package_id, 'tanpa paket → surgery_package_id null');
        $this->assertSame(SurgerySchedule::LOCATION_RUANG_BEDAH, $schedule->location_type);
        $this->assertSame('SCHEDULED', $schedule->status);

        // 2. Baris antrean BEDAH terbuat.
        $this->assertTrue(
            Queue::byStation(Queue::STATION_BEDAH)->where('visit_id', $visit->id)->exists(),
            'baris antrean BEDAH wajib ada'
        );

        // 3. REGRESI INTI: pasien muncul di papan Bedah (guard surgery_schedule lolos).
        $board    = app(BedahService::class)->getPatientQueue();
        $visitIds = collect($board)->pluck('visit.id')->all();
        $this->assertContains($visit->id, $visitIds, 'pasien tanpa paket harus tetap muncul di papan Bedah');
    }

    public function test_kirim_bedah_dengan_paket_menyimpan_paket_di_jadwal(): void
    {
        $visit = $this->makeRanapVisit();

        $package = new SurgeryPackage();
        $package->forceFill(['name' => 'Phaco + IOL', 'is_active' => true])->save();

        app(RanapService::class)->sendToBedah($visit, null, [
            'surgery_package_id' => $package->id,
        ]);

        $visit->refresh();
        $schedule = SurgerySchedule::findOrFail($visit->surgery_schedule_id);

        $this->assertSame($package->id, $schedule->surgery_package_id, 'paket dipilih → tersimpan di jadwal');
        $this->assertSame(SurgerySchedule::LOCATION_RUANG_BEDAH, $schedule->location_type);

        $board    = app(BedahService::class)->getPatientQueue();
        $visitIds = collect($board)->pluck('visit.id')->all();
        $this->assertContains($visit->id, $visitIds);
    }
}
