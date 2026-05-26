<?php

namespace App\Services;

use App\Models\BpjsVClaimLog;
use App\Models\IntegrationConfig;
use App\Models\Visit;
use Illuminate\Support\Facades\Http;

/**
 * BPJS VClaim — integrasi REST API VClaim.
 *
 * Semua method adalah PLACEHOLDER.
 * Implementasi actual memerlukan:
 *   - credentials: cons_id, secretkey, user_key (dari IntegrationConfig)
 *   - HMAC-SHA256 signature per request
 *   - Base URL: https://apijkn-dev.bpjs-kesehatan.go.id (dev) / https://apijkn.bpjs-kesehatan.go.id (prod)
 *
 * @see https://dvlp.bpjs-kesehatan.go.id
 */
class BpjsVClaimService
{
    private ?IntegrationConfig $config = null;

    public function boot(): void
    {
        $this->config = IntegrationConfig::where('system_name', 'VCLAIM')->first();
    }

    public function isEnabled(): bool
    {
        return $this->config?->is_enabled ?? false;
    }

    // =========================================================================
    // PESERTA
    // =========================================================================

    /**
     * GET /Peserta/noKartu/{noKartu}/tglSEP/{tglSEP}
     * atau GET /Peserta/NIK/{nik}/tglSEP/{tglSEP}
     */
    public function checkPeserta(string $identifier, string $type = 'NIK', string $tglSep = ''): array
    {
        $this->assertEnabled();

        // TODO: Implement HMAC-SHA256 auth + HTTP call
        // $response = Http::withHeaders($this->buildHeaders('GET', "/Peserta/{$type}/{$identifier}/tglSEP/{$tglSep}"))
        //     ->get($this->buildUrl("/Peserta/{$type}/{$identifier}/tglSEP/{$tglSep}"));

        return $this->placeholder('checkPeserta', ['identifier' => $identifier, 'type' => $type]);
    }

    // =========================================================================
    // SEP
    // =========================================================================

    /** POST /SEP */
    public function generateSep(array $data): array
    {
        $this->assertEnabled();

        $visitId = $data['visit_id'] ?? null;
        $result  = $this->placeholder('generateSep', $data);

        $this->log($visitId, 'GENERATE_SEP', $data, $result, 200, true);

        return $result;
    }

    /** DELETE /SEP/{noSEP} */
    public function cancelSep(string $noSep, string $alasan, ?string $visitId = null): array
    {
        $this->assertEnabled();

        $result = $this->placeholder('cancelSep', compact('noSep', 'alasan'));

        $this->log($visitId, 'CANCEL_SEP', compact('noSep', 'alasan'), $result, 200, true);

        return $result;
    }

    // =========================================================================
    // RUJUKAN
    // =========================================================================

    /** GET /Rujukan/{noRujukan} */
    public function checkRujukan(string $noRujukan, ?string $visitId = null): array
    {
        $this->assertEnabled();

        $result = $this->placeholder('checkRujukan', compact('noRujukan'));

        $this->log($visitId, 'CHECK_RUJUKAN', compact('noRujukan'), $result, 200, true);

        return $result;
    }

    // =========================================================================
    // SURAT KONTROL
    // =========================================================================

    /** POST /SuratKontrol */
    public function postSuratKontrol(array $data, ?string $visitId = null): array
    {
        $this->assertEnabled();

        $result = $this->placeholder('postSuratKontrol', $data);

        $this->log($visitId, 'POST_SURAT_KONTROL', $data, $result, 200, true);

        return $result;
    }

    /** GET /SuratKontrol/{noSuratKontrol} */
    public function getSuratKontrol(string $noSuratKontrol, ?string $visitId = null): array
    {
        $this->assertEnabled();

        return $this->placeholder('getSuratKontrol', compact('noSuratKontrol'));
    }

    // =========================================================================
    // KLAIM
    // =========================================================================

    /** POST /Klaim */
    public function submitKlaim(array $claimData, ?string $visitId = null): array
    {
        $this->assertEnabled();

        $result = $this->placeholder('submitKlaim', $claimData);

        $this->log($visitId, 'SUBMIT_CLAIM', $claimData, $result, 200, true);

        return $result;
    }

    /** GET /Klaim/SEP/{noSEP} */
    public function checkStatusKlaim(string $noSep, ?string $visitId = null): array
    {
        $this->assertEnabled();

        return $this->placeholder('checkStatusKlaim', compact('noSep'));
    }

    // =========================================================================
    // TEST CONNECTION
    // =========================================================================

    public function testConnection(): array
    {
        $this->assertEnabled();

        // TODO: call a lightweight endpoint (e.g. GET /Peserta test with dummy NIK)
        // For now: return config status
        return [
            'success' => true,
            'message' => 'VClaim connection test — placeholder. Implement actual HTTP call.',
            'system'  => 'VCLAIM',
        ];
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function assertEnabled(): void
    {
        $this->boot();

        if (! $this->isEnabled()) {
            throw new \Exception('Integrasi VClaim belum diaktifkan. Konfigurasi credentials terlebih dahulu.', 503);
        }
    }

    private function placeholder(string $method, array $input = []): array
    {
        return [
            'placeholder' => true,
            'method'      => $method,
            'input'       => $input,
            'message'     => "VClaim {$method} — implementasi pending. Aktifkan integrasi dan masukkan credentials.",
        ];
    }

    private function log(
        ?string $visitId,
        string $action,
        array $request,
        array $response,
        int $httpStatus,
        bool $isSuccess
    ): void {
        BpjsVClaimLog::create([
            'visit_id'         => $visitId,
            'action'           => $action,
            'request_payload'  => $request,
            'response_payload' => $response,
            'http_status'      => $httpStatus,
            'is_success'       => $isSuccess,
        ]);
    }
}
