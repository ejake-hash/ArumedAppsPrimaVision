<?php

namespace App\Services;

use App\Models\IntegrationConfig;
use Illuminate\Support\Facades\DB;

/**
 * BPJS Antrean Online — validasi kode booking dari JKN Mobile.
 *
 * PLACEHOLDER — implementasi setelah credentials tersedia.
 * Base URL: https://apijkn-dev.bpjs-kesehatan.go.id/antreanrs (dev)
 *
 * Actions logged ke bpjs_antrean_logs:
 *   VALIDATE_BOOKING / CHECK_QUOTA / CONFIRM
 */
class BpjsAntreanService
{
    private ?IntegrationConfig $config = null;

    public function boot(): void
    {
        $this->config = IntegrationConfig::where('system_name', 'ANTREAN')->first();
    }

    public function isEnabled(): bool
    {
        return $this->config?->is_enabled ?? false;
    }

    // =========================================================================
    // BOOKING VALIDATION
    // =========================================================================

    /**
     * Validate booking code dari JKN Mobile.
     * POST /antrean/validate-booking
     */
    public function validateBookingCode(string $bookingCode, string $tglPeriksa): array
    {
        $this->assertEnabled();

        $result = $this->placeholder('validateBookingCode', compact('bookingCode', 'tglPeriksa'));

        $this->log('VALIDATE_BOOKING', compact('bookingCode'), $result, true);

        return $result;
    }

    /**
     * Check quota antrean per poli per hari.
     * GET /antrean/quota-rest/{kodePoli}/{tglPeriksa}
     */
    public function checkQuota(string $kodePoli, string $tglPeriksa): array
    {
        $this->assertEnabled();

        return $this->placeholder('checkQuota', compact('kodePoli', 'tglPeriksa'));
    }

    /**
     * Confirm booking setelah pasien hadir.
     * POST /antrean/confirm-booking
     */
    public function confirmBooking(string $bookingCode, string $visitId): array
    {
        $this->assertEnabled();

        $result = $this->placeholder('confirmBooking', compact('bookingCode', 'visitId'));

        $this->log('CONFIRM', compact('bookingCode', 'visitId'), $result, true);

        return $result;
    }

    // =========================================================================
    // TEST
    // =========================================================================

    public function testConnection(): array
    {
        $this->assertEnabled();

        return [
            'success' => true,
            'message' => 'Antrean connection test — placeholder.',
            'system'  => 'ANTREAN',
        ];
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function assertEnabled(): void
    {
        $this->boot();

        if (! $this->isEnabled()) {
            throw new \Exception('Integrasi ANTREAN belum diaktifkan.', 503);
        }
    }

    private function placeholder(string $method, array $input = []): array
    {
        return [
            'placeholder' => true,
            'method'      => $method,
            'input'       => $input,
            'message'     => "Antrean {$method} — implementasi pending.",
        ];
    }

    private function log(string $action, array $request, array $response, bool $isSuccess): void
    {
        DB::table('bpjs_antrean_logs')->insert([
            'id'               => \Illuminate\Support\Str::uuid(),
            'action'           => $action,
            'request_payload'  => json_encode($request),
            'response_payload' => json_encode($response),
            'is_success'       => $isSuccess,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
