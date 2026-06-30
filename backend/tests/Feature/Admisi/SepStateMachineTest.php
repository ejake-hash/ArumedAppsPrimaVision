<?php

namespace Tests\Feature\Admisi;

use App\Models\IntegrationConfig;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\AdmisiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Karakterisasi state-machine penerbitan SEP (rancangan "SEP keluar dari DB::transaction").
 * Menguji LAPISAN STATE-MACHINE (claim ISSUING, TTL, outcome handling) yang menggantikan
 * lockForUpdate-sepanjang-HTTP — fixtur ringan (Visit), tanpa call BPJS riil.
 *
 * Verifikasi R2 (anti-race), R3 (dual-write observable), dan aturan code-'0'-keep-ISSUING.
 */
class SepStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): AdmisiService
    {
        return app(AdmisiService::class);
    }

    private function invokePrivate(string $method, ...$args): mixed
    {
        $m = new ReflectionMethod(AdmisiService::class, $method);
        $m->setAccessible(true);

        return $m->invoke($this->svc(), ...$args);
    }

    private function makeVisit(array $overrides = []): Visit
    {
        $patient = new Patient();
        $patient->forceFill(['name' => 'Pasien BPJS'])->save();

        $visit = new Visit();
        $visit->forceFill(array_merge([
            'patient_id'     => $patient->id,
            'visit_date'     => today()->toDateString(),
            'classification' => 'RAWAT_JALAN',
            'guarantor_type' => 'BPJS',
        ], $overrides))->save();

        return $visit;
    }

    // --- FASE 1: claimSepIssuing (anti-race) ---

    public function test_claim_sets_issuing_status(): void
    {
        $v = $this->makeVisit();

        $claimed = $this->invokePrivate('claimSepIssuing', $v->id);

        $this->assertInstanceOf(Visit::class, $claimed);
        $this->assertSame('ISSUING', $v->fresh()->sep_status);
        $this->assertNotNull($v->fresh()->sep_issuing_at);
    }

    public function test_claim_throws_422_when_already_has_sep(): void
    {
        $v = $this->makeVisit(['no_sep' => '0301R0010624V000999', 'sep_status' => 'ISSUED']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('sudah punya SEP');
        $this->invokePrivate('claimSepIssuing', $v->id);
    }

    public function test_claim_throws_409_when_issuing_is_fresh(): void
    {
        $v = $this->makeVisit(['sep_status' => 'ISSUING', 'sep_issuing_at' => now()]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('sedang diterbitkan');
        $this->invokePrivate('claimSepIssuing', $v->id);
    }

    public function test_claim_reclaims_stale_issuing(): void
    {
        // ISSUING basi (jauh di atas TTL) → boleh di-claim ulang (proses lama mati).
        $v = $this->makeVisit(['sep_status' => 'ISSUING', 'sep_issuing_at' => now()->subSeconds(9999)]);

        $claimed = $this->invokePrivate('claimSepIssuing', $v->id);

        $this->assertInstanceOf(Visit::class, $claimed);
        $this->assertTrue($v->fresh()->sep_issuing_at->gt(now()->subSeconds(60)), 'sep_issuing_at di-refresh.');
    }

    // --- Outcome handling (R3 + aturan code-0) ---

    public function test_mark_keeps_issuing_on_code_0(): void
    {
        $v = $this->makeVisit(['sep_status' => 'ISSUING', 'sep_issuing_at' => now()]);

        $this->invokePrivate('markFailedOrKeepIssuing', $v->id, ['metaData' => ['code' => '0', 'message' => 'Koneksi BPJS gagal']]);

        // code '0' (koneksi/timeout) AMBIGU → SEP mungkin terbit diam-diam → JANGAN FAILED.
        $this->assertSame('ISSUING', $v->fresh()->sep_status);
    }

    public function test_mark_sets_failed_on_deterministic_rejection(): void
    {
        $v = $this->makeVisit(['sep_status' => 'ISSUING', 'sep_issuing_at' => now()]);

        $this->invokePrivate('markFailedOrKeepIssuing', $v->id, ['metaData' => ['code' => '201', 'message' => 'Diagnosa Awal Tidak Boleh Kosong']]);

        $this->assertSame('FAILED', $v->fresh()->sep_status);
    }

    public function test_release_clears_issuing_when_no_sep(): void
    {
        $v = $this->makeVisit(['sep_status' => 'ISSUING', 'sep_issuing_at' => now()]);

        $this->invokePrivate('releaseIssuingIfNoSep', $v->id);

        $this->assertNull($v->fresh()->sep_status);
        $this->assertNull($v->fresh()->sep_issuing_at);
    }

    public function test_release_keeps_status_when_no_sep_present(): void
    {
        // Proses lain sudah menerbitkan (no_sep terisi) → release TIDAK boleh mengubah.
        $v = $this->makeVisit(['sep_status' => 'ISSUED', 'no_sep' => '0301R0010624V000888']);

        $this->invokePrivate('releaseIssuingIfNoSep', $v->id);

        $this->assertSame('ISSUED', $v->fresh()->sep_status);
    }

    // --- Correctness-floor: UNIQUE no_sep (23505 idempoten) ---

    public function test_persist_no_sep_idempotent_on_unique_violation(): void
    {
        $a = $this->makeVisit();
        $a->forceFill(['no_sep' => '0301R0010624V000777', 'sep_status' => 'ISSUED'])->save();

        $b = $this->makeVisit(['sep_status' => 'ISSUING', 'sep_issuing_at' => now()]);

        // Menautkan nomor yang SAMA dgn visit lain → UNIQUE no_sep tolak (23505) →
        // ditelan idempoten (tanpa exception), no_sep b tetap kosong.
        $this->invokePrivate('persistNoSepIdempotent', $b, '0301R0010624V000777', ['noSep' => '0301R0010624V000777']);

        $this->assertNull($b->fresh()->no_sep, 'no_sep b tetap kosong (UNIQUE menolak).');
    }

    // --- Feature flag (mekanisme keamanan: default OFF) ---

    public function test_flag_defaults_off_without_config(): void
    {
        // Tanpa IntegrationConfig VCLAIM → state-machine OFF → jalur lama dipakai.
        $this->assertFalse($this->invokePrivate('sepStateMachineEnabled'));
    }

    public function test_flag_on_when_integration_config_set(): void
    {
        IntegrationConfig::create([
            'system_name'   => 'VCLAIM',
            'configuration' => ['sep_state_machine' => true],
        ]);

        $this->assertTrue($this->invokePrivate('sepStateMachineEnabled'));
    }
}
