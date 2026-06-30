<?php

namespace Tests\Feature\Admisi;

use App\Models\Patient;
use App\Models\Visit;
use App\Services\AdmisiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Karakterisasi penomoran registrasi harian (REG-YYYYMMDD-NNN) di
 * AdmisiService::generateNoRegistrasi.
 *
 * Menutup temuan audit 30 Jun 2026: orderByDesc('no_registrasi') mengurutkan
 * sebagai STRING, sehingga setelah tembus 1000 nomor "...-999" dianggap lebih
 * besar dari "...-1000" secara leksikal → nomor registrasi KEMBAR.
 *
 * Prasyarat: DB Postgres test `arumed_test` (lihat phpunit.xml). RefreshDatabase
 * migrate:fresh tiap run sehingga tidak ada visit hari ini yang mengontaminasi.
 */
class NoRegistrasiSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function generate(): string
    {
        $svc = app(AdmisiService::class);
        $m = new ReflectionMethod($svc, 'generateNoRegistrasi');
        $m->setAccessible(true);

        return $m->invoke($svc);
    }

    private function makeVisit(string $noRegistrasi): void
    {
        $patient = new Patient();
        $patient->forceFill(['name' => 'Pasien Uji'])->save();

        $visit = new Visit();
        $visit->forceFill([
            'patient_id'     => $patient->id,
            'visit_date'     => today()->toDateString(),
            'classification' => 'RAWAT_JALAN',
            'guarantor_type' => 'UMUM',
            'no_registrasi'  => $noRegistrasi,
        ])->save();
    }

    private function prefix(): string
    {
        return 'REG-' . today()->format('Ymd') . '-';
    }

    /** Baseline: hari tanpa registrasi → mulai dari 001. */
    public function test_first_number_of_day_is_001(): void
    {
        $this->assertSame($this->prefix() . '001', $this->generate());
    }

    /** Baseline: increment dari nomor terakhir hari ini. */
    public function test_increments_from_last_number(): void
    {
        $this->makeVisit($this->prefix() . '005');

        $this->assertSame($this->prefix() . '006', $this->generate());
    }

    /**
     * REGRESI BUG: dengan ordering STRING, "...-999" > "...-1000" → next salah
     * (mengulang 1000). Harus diurut NUMERIK sehingga next = 1001.
     */
    public function test_orders_numerically_past_999(): void
    {
        $this->makeVisit($this->prefix() . '999');
        $this->makeVisit($this->prefix() . '1000');

        $this->assertSame($this->prefix() . '1001', $this->generate());
    }
}
