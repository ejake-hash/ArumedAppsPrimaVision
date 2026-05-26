<?php

namespace App\Http\Controllers;

use App\Services\DokterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DokterController extends Controller
{
    public function __construct(private readonly DokterService $service) {}

    // =========================================================================
    // ANTRIAN DOKTER
    // =========================================================================

    /** GET /dokter/antrian */
    public function indexAntrian(): JsonResponse
    {
        return $this->ok($this->service->getPatientQueue());
    }

    public function panggilAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->panggilAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dipanggil');
    }

    public function selesaiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->selesaiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Antrian selesai');
    }

    // =========================================================================
    // TAB 1 — OVERVIEW (readonly: triase + refraksi)
    // =========================================================================

    /** GET /dokter/kunjungan/{visitId} */
    public function showKunjungan(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getPatientData($visitId));
    }

    // =========================================================================
    // TAB 2 — ANAMNESE + SEGMEN ANTERIOR/POSTERIOR
    // =========================================================================

    private function segmenRules(string $prefix = 'sometimes'): array
    {
        $opts   = 'in:Normal,Tidak Normal,Tidak Dapat Dinilai';
        $fields = ['sa_kornea', 'sa_coa', 'sa_iris', 'sa_pupil', 'sa_lensa',
                   'sp_papil', 'sp_macula', 'sp_retina', 'sp_vitreous'];
        $rules  = [];

        foreach ($fields as $f) {
            $rules["{$f}_od"] = "nullable|{$opts}";
            $rules["{$f}_os"] = "nullable|{$opts}";
        }

        return $rules;
    }

    /** GET /dokter/kunjungan/{visitId}/tab2 */
    public function showTab2(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getTab2($visitId));
    }

    /** POST /dokter/kunjungan/{visitId}/tab2 */
    public function storeTab2(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate(array_merge([
            'anamnese'       => 'nullable|string|max:5000',
            'slitlamp_notes' => 'nullable|string|max:2000',
        ], $this->segmenRules()));

        try {
            $examination = $this->service->storeExamination($visitId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($examination, 'Tab 2 disimpan', 201);
    }

    /** PUT /dokter/kunjungan/{visitId}/tab2 */
    public function updateTab2(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate(array_merge([
            'anamnese'       => 'nullable|string|max:5000',
            'slitlamp_notes' => 'nullable|string|max:2000',
        ], $this->segmenRules()));

        try {
            $examination = $this->service->updateExamination($visitId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($examination, 'Tab 2 diperbarui');
    }

    // =========================================================================
    // TAB 3 — TINDAKAN + RESEP OBAT
    // =========================================================================

    /** GET /dokter/kunjungan/{visitId}/tindakan */
    public function indexTindakan(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getVisitServices($visitId));
    }

    /**
     * POST /dokter/kunjungan/{visitId}/tindakan
     * Body: { services: [{procedure_id, quantity, price, notes}] }
     */
    public function storeTindakan(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'services'                  => 'required|array|min:1',
            'services.*.procedure_id'   => 'required|uuid|exists:procedures,id',
            'services.*.quantity'       => 'nullable|integer|min:1',
            'services.*.price'          => 'nullable|numeric|min:0',
            'services.*.notes'          => 'nullable|string|max:255',
        ]);

        try {
            $services = $this->service->storeVisitServices($visitId, $validated['services']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($services, 'Tindakan disimpan', 201);
    }

    /** DELETE /dokter/tindakan/{id} */
    public function deleteTindakan(string $id): JsonResponse
    {
        try {
            $this->service->deleteVisitService($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Tindakan dihapus');
    }

    /** GET /dokter/kunjungan/{visitId}/resep */
    public function indexResep(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getPrescriptions($visitId));
    }

    /**
     * POST /dokter/kunjungan/{visitId}/resep
     * Body: { notes, items: [{medication_id, quantity, dose, frequency, route, duration_days, notes}] }
     */
    public function storeResep(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'notes'                       => 'nullable|string|max:500',
            'items'                       => 'required|array|min:1',
            'items.*.medication_id'       => 'required|uuid|exists:medications,id',
            'items.*.quantity'            => 'required|integer|min:1',
            'items.*.dose'                => 'nullable|string|max:100',
            'items.*.frequency'           => 'nullable|string|max:100',
            'items.*.route'               => 'nullable|string|max:100',
            'items.*.duration_days'       => 'nullable|integer|min:1',
            'items.*.notes'               => 'nullable|string|max:255',
        ]);

        try {
            $prescription = $this->service->storePrescription($visitId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Resep disimpan', 201);
    }

    // =========================================================================
    // TAB 4 — SOAP + ICD + PLANNING (KRITIS)
    // =========================================================================

    /** GET /dokter/kunjungan/{visitId}/tab4 */
    public function showTab4(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getTab4($visitId));
    }

    /** POST /dokter/kunjungan/{visitId}/tab4 */
    public function storeTab4(Request $request, string $visitId): JsonResponse
    {
        $validated = $this->validateTab4($request);

        try {
            $result = $this->service->storePlanning($visitId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Tab 4 disimpan', 201);
    }

    /** PUT /dokter/kunjungan/{visitId}/tab4 */
    public function updateTab4(Request $request, string $visitId): JsonResponse
    {
        $validated = $this->validateTab4($request);

        try {
            $result = $this->service->updatePlanning($visitId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Tab 4 diperbarui');
    }

    private function validateTab4(Request $request): array
    {
        return $request->validate([
            'soap_subjective'    => 'nullable|string|max:5000',
            'soap_objective'     => 'nullable|string|max:5000',
            'soap_assessment'    => 'nullable|string|max:5000',
            'soap_plan'          => 'nullable|string|max:5000',
            'diagnosis_utama'    => 'required|string|max:20',
            'diagnosis_sekunder' => 'nullable|array',
            'diagnosis_sekunder.*' => 'string|max:20',
            'tindakan_codes'     => 'nullable|array',
            'tindakan_codes.*'   => 'string|max:20',
            'planning'           => 'required|in:PULANG_BEROBAT_JALAN,BEDAH,RUJUK',
            'surgery_package_id' => 'nullable|uuid|exists:surgery_packages,id',
            'surgery_schedule_id' => 'nullable|uuid|exists:surgery_schedules,id',

            // Follow-up (opsional, hanya dalam PULANG_BEROBAT_JALAN)
            'follow_up_date'     => 'nullable|date|after_or_equal:today',
            'follow_up_reason'   => 'nullable|string|max:500',
        ]);
    }

    // =========================================================================
    // FINALIZE
    // =========================================================================

    /** POST /dokter/kunjungan/{visitId}/finalize */
    public function finalizeKunjungan(string $visitId): JsonResponse
    {
        try {
            $examination = $this->service->finalizeKunjungan($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($examination, 'Pemeriksaan dikunci. Pasien diteruskan ke stasiun berikutnya.');
    }

    // =========================================================================
    // FOLLOW-UP STANDALONE
    // =========================================================================

    /** POST /dokter/kunjungan/{visitId}/follow-up */
    public function storeFollowUp(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'follow_up_date'   => 'required|date|after_or_equal:today',
            'follow_up_reason' => 'nullable|string|max:500',
        ]);

        try {
            $visit = $this->service->storeFollowUp($visitId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($visit, 'Follow-up dijadwalkan', 201);
    }

    /** PUT /dokter/kunjungan/{visitId}/follow-up */
    public function updateFollowUp(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'follow_up_date'   => 'required|date|after_or_equal:today',
            'follow_up_reason' => 'nullable|string|max:500',
        ]);

        try {
            $visit = $this->service->updateFollowUp($visitId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($visit, 'Follow-up diperbarui');
    }

    /** DELETE /dokter/kunjungan/{visitId}/follow-up */
    public function deleteFollowUp(string $visitId): JsonResponse
    {
        try {
            $visit = $this->service->deleteFollowUp($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($visit, 'Follow-up dibatalkan');
    }

    // =========================================================================
    // ORDER + HASIL PENUNJANG
    // =========================================================================

    /** GET /dokter/kunjungan/{visitId}/order-penunjang */
    public function indexOrderPenunjang(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getOrderPenunjang($visitId));
    }

    /**
     * POST /dokter/order-penunjang
     * Body: { visit_id, test_type (OCT/USG/Biometri/Topografi), eye_side, notes }
     */
    public function storeOrderPenunjang(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id'  => 'required|uuid|exists:visits,id',
            'test_type' => 'required|in:OCT,USG,Biometri,Topografi',
            'eye_side'  => 'nullable|in:OD,OS,OU',
            'notes'     => 'nullable|string|max:500',
        ]);

        try {
            $order = $this->service->storeOrderPenunjang($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($order, 'Order penunjang dibuat', 201);
    }

    /** DELETE /dokter/order-penunjang/{id} */
    public function cancelOrderPenunjang(string $id): JsonResponse
    {
        try {
            $this->service->cancelOrderPenunjang($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Order penunjang dibatalkan');
    }

    /** GET /dokter/kunjungan/{visitId}/hasil-penunjang */
    public function indexHasilPenunjang(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getHasilPenunjang($visitId));
    }

    /** GET /dokter/kunjungan/{visitId}/iol-rekomendasi */
    public function showIolRekomendasi(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getIolRekomendasi($visitId));
    }

    // =========================================================================
    // RESUME MEDIS
    // =========================================================================

    /** GET /dokter/kunjungan/{visitId}/resume-medis */
    public function showResumeMedis(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getResumeMedis($visitId));
    }

    /** POST /dokter/kunjungan/{visitId}/resume-medis */
    public function generateResumeMedis(string $visitId): JsonResponse
    {
        try {
            $resume = $this->service->generateMedicalResume($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($resume, 'Resume medis di-generate', 201);
    }

    /** PUT /dokter/resume-medis/{id} */
    public function updateResumeMedis(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'resume_s' => 'nullable|string|max:5000',
            'resume_o' => 'nullable|string|max:5000',
            'resume_a' => 'nullable|string|max:5000',
            'resume_p' => 'nullable|string|max:5000',
        ]);

        try {
            $resume = $this->service->updateResumeMedis($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($resume, 'Resume medis diperbarui');
    }

    /** POST /dokter/resume-medis/{id}/finalize */
    public function finalizeResumeMedis(string $id): JsonResponse
    {
        try {
            $resume = $this->service->finalizeResumeMedis($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($resume, 'Resume medis dikunci');
    }

    // =========================================================================
    // RUJUKAN KELUAR
    // =========================================================================

    /** POST /dokter/rujukan-keluar */
    public function storeRujukanKeluar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id'           => 'required|uuid|exists:visits,id',
            'faskes_tujuan_kode' => 'required|string|max:20',
            'faskes_tujuan_nama' => 'nullable|string|max:255',
            'kode_spesialis'     => 'nullable|string|max:10',
            'urgency'            => 'nullable|in:ELEKTIF,SEGERA,EMERGENCY',
            'diagnosa_rujukan'   => 'required|string|max:20',
            'diagnosa_nama'      => 'nullable|string|max:255',
            'catatan_rujukan'    => 'nullable|string|max:1000',
        ]);

        try {
            $rujukan = $this->service->storeRujukanKeluar($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($rujukan, 'Surat rujukan dibuat', 201);
    }

    // =========================================================================
    // INBOX TTD
    // =========================================================================

    /** GET /dokter/notifikasi */
    public function indexNotifikasi(): JsonResponse
    {
        return $this->ok($this->service->getInboxNotifications());
    }

    /** PUT /dokter/notifikasi/{id}/baca */
    public function bacaNotifikasi(string $id): JsonResponse
    {
        try {
            $notif = $this->service->markNotificationRead($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }

        return $this->ok($notif, 'Notifikasi ditandai dibaca');
    }

    /**
     * POST /dokter/dokumen/{id}/tanda-tangan
     * Body: { pin: "123456" }
     */
    public function tandaTanganDokumen(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'pin' => 'required|string|min:4|max:8',
        ]);

        try {
            $document = $this->service->signDocument($id, $request->pin);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($document, 'Dokumen berhasil ditandatangani');
    }

    /**
     * POST /dokter/dokumen/{id}/tolak
     * Body: { alasan: "..." }
     */
    public function tolakDokumen(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'alasan' => 'required|string|min:5|max:500',
        ]);

        try {
            $document = $this->service->rejectDocument($id, $request->alasan);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($document, 'Dokumen ditolak');
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
