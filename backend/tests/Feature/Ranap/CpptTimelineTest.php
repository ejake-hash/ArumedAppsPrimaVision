<?php

namespace Tests\Feature\Ranap;

use App\Models\DoctorExamination;
use App\Models\NurseCpptEntry;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\RanapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Karakterisasi timeline CPPT terintegrasi RanapService::cpptEntries.
 *
 * Tujuan (req user 30 Jun 2026): CPPT dari RJ/IGD + pemeriksaan dokter MUNCUL di
 * Ranap dengan label sumber masing-masing, lintas-episode pasien, sehingga dokter
 * tak kehilangan riwayat. Entri kunjungan lain & pemeriksaan dokter = read-only.
 */
class CpptTimelineTest extends TestCase
{
    use RefreshDatabase;

    private function patient(): Patient
    {
        $p = new Patient();
        $p->forceFill(['name' => 'Pasien CPPT'])->save();
        return $p;
    }

    private function visit(Patient $p, array $attrs): Visit
    {
        $v = new Visit();
        $v->forceFill(array_merge([
            'patient_id'     => $p->id,
            'visit_date'     => today()->toDateString(),
            'classification' => 'RAWAT_INAP',
            'guarantor_type' => 'UMUM',
        ], $attrs))->save();
        return $v;
    }

    private function cppt(string $visitId, string $soap): NurseCpptEntry
    {
        $e = new NurseCpptEntry();
        $e->forceFill(['visit_id' => $visitId, 'ppa_role' => 'PERAWAT', 'soap_s' => $soap])->save();
        return $e;
    }

    public function test_timeline_gabung_cppt_exam_lintas_episode_dengan_label_sumber(): void
    {
        $p = $this->patient();

        // Kunjungan RANAP saat ini (admit 1 jam lalu → CPPT baru = fase Rawat Inap).
        $ranap = $this->visit($p, ['jenis_pelayanan' => 'RANAP', 'admission_at' => now()->subHour()]);
        $this->cppt($ranap->id, 'cppt ranap');

        // Pemeriksaan dokter pada kunjungan ini (read-only di CPPT).
        $exam = new DoctorExamination();
        $exam->forceFill(['visit_id' => $ranap->id, 'soap_subjective' => 'anamnesa dokter', 'soap_plan' => 'rencana'])->save();

        // Kunjungan IGD lain milik pasien yang sama (riwayat episode lain).
        $igd = $this->visit($p, ['jenis_pelayanan' => 'RAJAL', 'igd_arrival_at' => now()->subDays(3), 'visit_date' => today()->subDays(3)->toDateString()]);
        $this->cppt($igd->id, 'cppt igd');

        $timeline = app(RanapService::class)->cpptEntries($ranap);

        // 3 entri: 2 CPPT + 1 pemeriksaan dokter, lintas-kunjungan.
        $this->assertCount(3, $timeline);

        $bySoap = collect($timeline)->keyBy('soap_s');

        // CPPT Ranap (kunjungan ini) → sumber Rawat Inap, editable.
        $this->assertSame('Rawat Inap', $bySoap['cppt ranap']['source']);
        $this->assertTrue($bySoap['cppt ranap']['editable']);
        $this->assertTrue($bySoap['cppt ranap']['is_current']);

        // CPPT IGD (kunjungan lain) → sumber IGD, read-only.
        $this->assertSame('IGD', $bySoap['cppt igd']['source']);
        $this->assertFalse($bySoap['cppt igd']['editable']);
        $this->assertFalse($bySoap['cppt igd']['is_current']);

        // Pemeriksaan dokter → entry_type EXAM, read-only, terpetakan S/P.
        $examRow = collect($timeline)->firstWhere('entry_type', 'EXAM');
        $this->assertNotNull($examRow);
        $this->assertFalse($examRow['editable']);
        $this->assertSame('anamnesa dokter', $examRow['soap_s']);
        $this->assertSame('rencana', $examRow['soap_p']);
        $this->assertSame('DOKTER', $examRow['ppa_role']);
    }

    public function test_cppt_sebelum_admission_dilabeli_rawat_jalan(): void
    {
        $p = $this->patient();
        $ranap = $this->visit($p, ['jenis_pelayanan' => 'RANAP', 'admission_at' => now()]);

        // Entri DIBUAT sebelum admission_at (fase rawat jalan pada visit yang sama).
        $e = $this->cppt($ranap->id, 'pra admit');
        \Illuminate\Support\Facades\DB::table('nurse_cppt_entries')
            ->where('id', $e->id)->update(['created_at' => now()->subHours(3)]);

        $timeline = app(RanapService::class)->cpptEntries($ranap->fresh());
        $row = collect($timeline)->firstWhere('soap_s', 'pra admit');

        $this->assertSame('Rawat Jalan', $row['source']);
        $this->assertTrue($row['editable']); // tetap CPPT kunjungan ini → bisa diedit
    }
}
