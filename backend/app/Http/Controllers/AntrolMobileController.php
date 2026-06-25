<?php

namespace App\Http\Controllers;

use App\Services\AntrolMobileService;
use App\Services\AntrolTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WS Antrean sisi RS — endpoint yang DIPANGGIL Mobile JKN / server BPJS (Sisi B).
 * Spec: Docs/Antrol.md (bagian "Web Service Antrean - RS (Diakses oleh Mobile JKN)").
 *
 * Semua respon memakai ENVELOPE BPJS Antrean { response, metadata:{code,message} }:
 * CATATAN: WS Antrean memakai key `metadata` (huruf kecil), BERBEDA dari VClaim
 * yang memakai `metaData` (camelCase). Verifikator UAT mencocokkan persis.
 *   200 = sukses, 201 = gagal, 202 = pasien baru (khusus Ambil Antrean).
 * Auth (kecuali token) lewat middleware VerifyAntrolToken (x-token + x-username).
 *
 * Endpoint inbound ini bersifat data-driven lokal; tidak memanggil balik BPJS.
 */
class AntrolMobileController extends Controller
{
    public function __construct(
        private readonly AntrolTokenService  $tokens,
        private readonly AntrolMobileService $service,
    ) {}

    // =========================================================================
    // B1 — TOKEN  (GET /antrol/token ; header x-username + x-password)
    // =========================================================================

    public function token(Request $request): JsonResponse
    {
        $username = $request->header('x-username');
        $password = $request->header('x-password');

        if (! $this->tokens->verifyCredentials($username, $password)) {
            return $this->bpjs(null, 201, 'Username atau password salah.', 'TOKEN', $request);
        }

        $token = $this->tokens->issue($username);

        return $this->bpjs(['token' => $token], 200, 'Ok', 'TOKEN', $request);
    }

    // =========================================================================
    // B2–B11 — diisi pada Poin 5/6/7. Skeleton agar route resolve & terdokumentasi.
    // =========================================================================

    /** B2 POST /antrol/status — Status Antrean per poli. */
    public function status(Request $request): JsonResponse
    {
        return $this->run($request, 'STATUS', fn () => $this->service->statusAntrean($request->all()));
    }

    /** B3 POST /antrol/ambil — Ambil Antrean (bisa 202 pasien baru). */
    public function ambil(Request $request): JsonResponse
    {
        return $this->run($request, 'AMBIL', fn () => $this->service->ambilAntrean($request->all()));
    }

    /** B4 POST /antrol/sisa — Sisa Antrean (by kodebooking). */
    public function sisa(Request $request): JsonResponse
    {
        return $this->run($request, 'SISA', fn () => $this->service->sisaAntrean($request->all()));
    }

    /** B5 POST /antrol/batal — Batal Antrean (by kodebooking). */
    public function batal(Request $request): JsonResponse
    {
        return $this->run($request, 'BATAL', fn () => $this->service->batalAntrean($request->all()));
    }

    /** B6 POST /antrol/checkin — Check In pasien. */
    public function checkin(Request $request): JsonResponse
    {
        return $this->run($request, 'CHECKIN', fn () => $this->service->checkin($request->all()));
    }

    /** B7 POST /antrol/pasien-baru — Info Pasien Baru → buat RM. */
    public function pasienBaru(Request $request): JsonResponse
    {
        return $this->run($request, 'PASIEN_BARU', fn () => $this->service->pasienBaru($request->all()));
    }

    /** B8 POST /antrol/jadwal-operasi — Jadwal Operasi RS. */
    public function jadwalOperasi(Request $request): JsonResponse
    {
        return $this->run($request, 'JADWAL_OPERASI', fn () => $this->service->jadwalOperasi($request->all()));
    }

    /** B9 POST /antrol/jadwal-operasi-pasien — Jadwal Operasi per pasien. */
    public function jadwalOperasiPasien(Request $request): JsonResponse
    {
        return $this->run($request, 'JADWAL_OPERASI_PASIEN', fn () => $this->service->jadwalOperasiPasien($request->all()));
    }

    /** B10 POST /antrol/farmasi/ambil — Ambil Antrean Farmasi. */
    public function farmasiAmbil(Request $request): JsonResponse
    {
        return $this->run($request, 'FARMASI_AMBIL', fn () => $this->service->farmasiAmbil($request->all()));
    }

    /** B11 POST /antrol/farmasi/status — Status Antrean Farmasi. */
    public function farmasiStatus(Request $request): JsonResponse
    {
        return $this->run($request, 'FARMASI_STATUS', fn () => $this->service->farmasiStatus($request->all()));
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Jalankan handler service yang mengembalikan ['code'=>int,'message'=>string,'response'=>mixed]
     * lalu bungkus ke envelope BPJS. Service belum diimplementasi (Poin 5+) → 501.
     */
    private function run(Request $request, string $action, callable $fn): JsonResponse
    {
        try {
            $r = $fn();

            return $this->bpjs($r['response'] ?? null, $r['code'] ?? 200, $r['message'] ?? 'Ok', $action, $request);
        } catch (\BadMethodCallException $e) {
            // Skeleton belum diimplementasi.
            return $this->bpjs(null, 201, 'Belum diimplementasi.', $action, $request);
        } catch (\Throwable $e) {
            return $this->bpjs(null, 201, $e->getMessage(), $action, $request);
        }
    }

    /**
     * Envelope respon BPJS + audit ke bpjs_antrean_logs (arah INBOUND dari Mobile JKN).
     */
    private function bpjs(mixed $response, int $code, string $message, string $action, Request $request): JsonResponse
    {
        try {
            DB::table('bpjs_antrean_logs')->insert([
                'id'               => (string) Str::uuid(),
                'visit_id'         => null,
                'action'           => 'INBOUND_' . $action,
                'booking_code'     => $request->input('kodebooking'),
                'request_payload'  => json_encode($request->except(['x-password'])),
                'response_payload' => json_encode(['code' => $code, 'message' => $message]),
                'http_status'      => 200,
                'is_success'       => $code === 200 || $code === 202,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            // logging tidak boleh menggagalkan respon ke BPJS
        }

        return response()->json([
            'response' => $response,
            'metadata' => ['code' => $code, 'message' => $message],
        ], 200);
    }
}
