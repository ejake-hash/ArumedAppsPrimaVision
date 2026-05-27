<?php

namespace App\Http\Controllers;

use App\Services\RekamMedisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RekamMedisController extends Controller
{
    public function __construct(private readonly RekamMedisService $service) {}

    // =========================================================================
    // PASIEN
    // =========================================================================

    /**
     * GET /rekam-medis/pasien?keyword=&mode=
     * Pencarian pasien untuk modul RME (mode: nama | rm | nik).
     */
    public function cariPasien(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => 'required|string|min:1',
            'mode'    => 'nullable|in:nama,rm,nik',
        ]);

        return $this->ok($this->service->searchPatient($validated['keyword'], $validated['mode'] ?? null));
    }

    /**
     * GET /rekam-medis/pasien/{patientId}
     * Full clinical timeline for a patient.
     */
    public function riwayatPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->service->getVisitHistory($patientId));
    }

    /**
     * GET /rekam-medis/pasien/{patientId}/kunjungan
     * Paginated visit list.
     */
    public function indexKunjungan(string $patientId): JsonResponse
    {
        return $this->ok($this->service->indexKunjungan($patientId));
    }

    // =========================================================================
    // DOKUMEN
    // =========================================================================

    /**
     * GET /rekam-medis/dokumen
     * Query: patient_id, visit_id, status, station, per_page
     */
    public function indexDokumen(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexDokumen(
            $request->only(['patient_id', 'visit_id', 'status', 'station', 'per_page'])
        ));
    }

    /** GET /rekam-medis/dokumen/{id} */
    public function showDokumen(string $id): JsonResponse
    {
        return $this->ok($this->service->showDokumen($id));
    }

    /**
     * POST /rekam-medis/dokumen
     * Create new patient document (DRAFT).
     * Body: { patient_id, visit_id?, document_type_id, created_by_station }
     */
    public function storeDokumen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id'         => 'required|uuid|exists:patients,id',
            'visit_id'           => 'nullable|uuid|exists:visits,id',
            'document_type_id'   => 'required|uuid|exists:document_types,id',
            'created_by_station' => 'required|in:ADMISI,TRIASE,REFRAKSIONIS,DOKTER,PENUNJANG,BEDAH,FARMASI,KASIR',
        ]);

        try {
            $document = $this->service->storeDokumen($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($document, 'Dokumen dibuat', 201);
    }

    /** PUT /rekam-medis/dokumen/{id} */
    public function updateDokumen(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'document_type_id'   => 'sometimes|uuid|exists:document_types,id',
            'created_by_station' => 'sometimes|in:ADMISI,TRIASE,REFRAKSIONIS,DOKTER,PENUNJANG,BEDAH,FARMASI,KASIR',
        ]);

        try {
            $document = $this->service->updateDokumen($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($document, 'Dokumen diperbarui');
    }

    /**
     * POST /rekam-medis/dokumen/{id}/submit
     * DRAFT → WAITING_SIGNATURE.
     * Derives pending_signature_roles from document_type.required_signatures.
     * Creates Notification for each signer.
     */
    public function submitDokumen(string $id): JsonResponse
    {
        try {
            $document = $this->service->submitDokumen($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($document, 'Dokumen disubmit. Notifikasi TTD telah dikirim.');
    }

    /**
     * POST /rekam-medis/dokumen/{id}/void
     * Body: { alasan }
     * Admin-only: invalidate document + QR Code.
     */
    public function voidDokumen(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'alasan' => 'required|string|min:5|max:500',
        ]);

        try {
            $document = $this->service->voidDokumen($id, $request->alasan);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($document, 'Dokumen di-void. QR Code dinonaktifkan.');
    }

    /**
     * GET /rekam-medis/dokumen/{id}/cetak
     * Returns structured data for PDF rendering via Puppeteer.
     * Increments printed_count.
     */
    public function cetakDokumen(string $id): JsonResponse
    {
        try {
            $pdfData = $this->service->generatePdf($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($pdfData, 'Data PDF siap cetak');
    }

    /**
     * POST /rekam-medis/dokumen/{id}/resend-notif
     * Resend signature request notification.
     */
    public function resendNotifDokumen(string $id): JsonResponse
    {
        try {
            $this->service->resendNotifDokumen($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Notifikasi TTD dikirim ulang');
    }

    // =========================================================================
    // QR VERIFICATION
    // =========================================================================

    /**
     * GET /rekam-medis/verifikasi/{token}
     * Public scan endpoint — verify document by QR token.
     * Tracks scan_count for analytics.
     */
    public function verifikasiDokumen(string $token): JsonResponse
    {
        $result = $this->service->verifyDocument($token);

        $status = $result['valid'] ? 200 : 404;

        return response()->json([
            'success' => $result['valid'],
            'data'    => $result,
            'message' => $result['message'],
            'errors'  => null,
        ], $status);
    }

    // =========================================================================
    // MEDICAL RECORD (generic + versioning)
    // =========================================================================

    /** GET /rekam-medis/medical-record/{visitId} */
    public function showMedicalRecord(string $visitId): JsonResponse
    {
        return $this->ok($this->service->showMedicalRecord($visitId));
    }

    /**
     * POST /rekam-medis/medical-record
     * Body: { visit_id, patient_id, document_type_id?, form_data }
     */
    public function storeMedicalRecord(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id'         => 'required|uuid|exists:visits,id',
            'patient_id'       => 'required|uuid|exists:patients,id',
            'document_type_id' => 'nullable|uuid|exists:document_types,id',
            'form_data'        => 'required|array',
        ]);

        try {
            $record = $this->service->storeMedicalRecord($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Rekam medis dibuat', 201);
    }

    /**
     * PUT /rekam-medis/medical-record/{id}
     * Saves version snapshot before updating.
     * Body: { form_data, change_reason? }
     */
    public function updateMedicalRecord(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'form_data'     => 'required|array',
            'change_reason' => 'nullable|string|max:255',
        ]);

        try {
            $record = $this->service->updateMedicalRecord($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Rekam medis diperbarui. Versi sebelumnya disimpan.');
    }

    /** GET /rekam-medis/medical-record/{id}/versions */
    public function versionsMedicalRecord(string $id): JsonResponse
    {
        return $this->ok($this->service->getVersionsMedicalRecord($id));
    }

    // =========================================================================
    // NOTIFICATIONS
    // =========================================================================

    /** GET /rekam-medis/notifikasi */
    public function indexNotifikasi(): JsonResponse
    {
        return $this->ok($this->service->indexNotifikasi());
    }

    /** PUT /rekam-medis/notifikasi/{id}/baca */
    public function bacaNotifikasi(string $id): JsonResponse
    {
        try {
            $notif = $this->service->bacaNotifikasi($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }

        return $this->ok($notif, 'Notifikasi ditandai dibaca');
    }

    // =========================================================================
    // RESPONSE HELPERS
    // =========================================================================

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }

    private function error(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
