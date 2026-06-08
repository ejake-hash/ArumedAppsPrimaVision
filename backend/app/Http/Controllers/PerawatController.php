<?php

namespace App\Http\Controllers;

use App\Services\PerawatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerawatController extends Controller
{
    public function __construct(private readonly PerawatService $service) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    public function indexAntrian(): JsonResponse
    {
        return $this->ok($this->service->getPatientQueue());
    }

    public function showKunjungan(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getKunjungan($visitId));
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
            $result = $this->service->selesaiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Antrian triase selesai');
    }

    /** PUT /perawat/antrian/{id}/mulai — CALLED → IN_PROGRESS */
    public function mulaiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->mulaiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien sedang dilayani');
    }

    public function lewatiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->lewatiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dilewati — dipindah ke akhir antrean');
    }

    /**
     * PUT /perawat/antrian/{queueId}/dahulukan
     * Dahulukan pasien (mis. jadwal dokter hampir habis) → naik ke atas antrean TRIASE.
     */
    public function dahulukanAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->dahulukanAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien didahulukan — naik ke atas antrean');
    }

    /**
     * PUT /perawat/antrian/{queueId}/skip
     * Lewati Triase (pasien tidak perlu triase) → asesmen di-skip, antrean lanjut.
     */
    public function skipTriase(string $id): JsonResponse
    {
        try {
            $result = $this->service->skipTriase($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Triase dilewati — pasien lanjut ke antrean berikutnya');
    }

    /**
     * POST /perawat/antrian/{queueId}/kirim-ke-bedah
     * PREOP_BEDAH: setelah TR+REF finalize, kirim pasien ke antrian BEDAH (skip DOKTER).
     */
    public function kirimKeBedah(string $id): JsonResponse
    {
        try {
            $result = $this->service->kirimKeBedah($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Pasien dikirim ke antrian Bedah');
    }

    /**
     * POST /perawat/antrian/{queueId}/kirim-ke-ranap
     * Pre-op RAWAT INAP (Fase 8B): setelah TR+REF finalize, masuk papan Menunggu Kamar.
     */
    public function kirimKeRanap(string $id): JsonResponse
    {
        try {
            $result = $this->service->kirimKeRanap($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Pasien dikirim ke Rawat Inap (Menunggu Kamar)');
    }

    // =========================================================================
    // ASESMEN
    // =========================================================================

    public function showAsesmen(string $visitId): JsonResponse
    {
        $assessment = $this->service->getAsesmen($visitId);

        // Repopulate tiket Dokter (D-NNN) saat pasien yang sudah finalized dipilih ulang,
        // supaya tombol "Cetak Tiket Dokter" tetap aktif (cetak ulang). Null kalau partner
        // TR belum selesai — getDoctorTicket() hanya menemukan antrian DOKTER bila gate lolos.
        if ($assessment?->is_finalized) {
            $assessment->doctor_ticket = $this->service->doctorTicket($visitId);
        }

        return $this->ok($assessment);
    }

    public function storeAsesmen(Request $request): JsonResponse
    {
        // Tidak ada field wajib untuk perawat — semua TTV/keluhan/alergi opsional
        // (hanya batas nilai yang divalidasi bila diisi).
        $validated = $request->validate([
            'visit_id'         => 'required|uuid|exists:visits,id',
            'td_sistol'        => 'nullable|integer|between:50,300',
            'td_diastol'       => 'nullable|integer|between:30,200',
            'kgd'              => 'nullable|numeric|between:20,800',
            'nadi'             => 'nullable|integer|between:20,250',
            'suhu'             => 'nullable|numeric|between:30,45',
            'respirasi'        => 'nullable|integer|between:5,60',
            'spo2'             => 'nullable|numeric|between:50,100',
            'pain_scale'       => 'nullable|integer|between:0,10',
            'berat_badan'      => 'nullable|numeric|between:1,300',
            'tinggi_badan'     => 'nullable|numeric|between:30,250',
            'has_allergy'      => 'nullable|boolean',
            'allergy_detail'   => 'required_if:has_allergy,true|nullable|string|max:500',
            'chief_complaint'  => 'nullable|string|max:1000',
            'rps'              => 'nullable|string|max:2000',
            'assessment_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $assessment = $this->service->storeAssessment($validated['visit_id'], $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($assessment, 'Asesmen berhasil disimpan', 201);
    }

    public function updateAsesmen(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'td_sistol'        => 'sometimes|integer|between:50,300',
            'td_diastol'       => 'sometimes|integer|between:30,200',
            'nadi'             => 'sometimes|integer|between:20,250',
            'suhu'             => 'sometimes|numeric|between:30,45',
            'respirasi'        => 'sometimes|integer|between:5,60',
            'spo2'             => 'nullable|numeric|between:50,100',
            'kgd'              => 'nullable|numeric|between:20,800',
            'pain_scale'       => 'nullable|integer|between:0,10',
            'berat_badan'      => 'nullable|numeric|between:1,300',
            'tinggi_badan'     => 'nullable|numeric|between:30,250',
            'has_allergy'      => 'sometimes|boolean',
            'allergy_detail'   => 'required_if:has_allergy,true|nullable|string|max:500',
            'chief_complaint'  => 'sometimes|string|max:1000',
            'rps'              => 'nullable|string|max:2000',
            'assessment_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $assessment = $this->service->updateAssessment($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($assessment, 'Asesmen diperbarui');
    }

    public function finalizeAsesmen(string $id): JsonResponse
    {
        try {
            $assessment = $this->service->finalizeAssessment($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        // Sertakan tiket Dokter (D-NNN) bila gate TR sudah lolos & antrian DOKTER dibuat.
        // Frontend (PerawatView) memunculkan tombol "Cetak Tiket Dokter" bila ada.
        $assessment->doctor_ticket = $this->service->doctorTicket($assessment->visit_id);

        return $this->ok($assessment, 'Asesmen dikunci. Data tidak bisa diubah.');
    }

    /**
     * POST /perawat/asesmen/{id}/reopen
     * Buka kunci asesmen triase (periksa ulang atas permintaan dokter).
     */
    public function reopenAsesmen(string $id): JsonResponse
    {
        try {
            $assessment = $this->service->reopenAssessment($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($assessment, 'Asesmen triase dibuka kembali untuk diperiksa ulang.');
    }

    // =========================================================================
    // CPPT — Catatan Perkembangan Pasien Terintegrasi
    // =========================================================================

    /** POST /perawat/cppt — tambah CPPT entry baru */
    public function storeCppt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id'   => 'required|uuid|exists:visits,id',
            'td_sistol'  => 'nullable|integer|between:50,300',
            'td_diastol' => 'nullable|integer|between:30,200',
            'nadi'       => 'nullable|integer|between:20,250',
            'suhu'       => 'nullable|numeric|between:30,45',
            'respirasi'  => 'nullable|integer|between:5,60',
            'spo2'       => 'nullable|numeric|between:50,100',
            'kgd'        => 'nullable|numeric|between:20,800',
            'pain_scale' => 'nullable|integer|between:0,10',
            // notes ATAU SOAP harus ada (SOAP opsional bila hari itu hanya TTV+notes).
            'notes'      => 'nullable|required_without_all:soap_s,soap_a,soap_p|string|max:2000',
            'soap_s'     => 'nullable|string|max:2000',
            'soap_o'     => 'nullable|string|max:2000',
            'soap_a'     => 'nullable|string|max:2000',
            'soap_p'     => 'nullable|string|max:2000',
            'instruksi'  => 'nullable|string|max:2000',
        ]);

        try {
            $entry = $this->service->addCpptEntry($validated['visit_id'], $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($entry, 'CPPT ditambahkan', 201);
    }

    /** PUT /perawat/cppt/{id} — edit CPPT entry (soft-edit dgn edited_at) */
    public function updateCppt(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'td_sistol'  => 'nullable|integer|between:50,300',
            'td_diastol' => 'nullable|integer|between:30,200',
            'nadi'       => 'nullable|integer|between:20,250',
            'suhu'       => 'nullable|numeric|between:30,45',
            'respirasi'  => 'nullable|integer|between:5,60',
            'spo2'       => 'nullable|numeric|between:50,100',
            'kgd'        => 'nullable|numeric|between:20,800',
            'pain_scale' => 'nullable|integer|between:0,10',
            'notes'      => 'sometimes|nullable|string|max:2000',
            'soap_s'     => 'nullable|string|max:2000',
            'soap_o'     => 'nullable|string|max:2000',
            'soap_a'     => 'nullable|string|max:2000',
            'soap_p'     => 'nullable|string|max:2000',
            'instruksi'  => 'nullable|string|max:2000',
        ]);

        try {
            $entry = $this->service->updateCpptEntry($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($entry, 'CPPT diperbarui');
    }

    /** POST /perawat/cppt/{id}/sign — tanda tangan PIN (paraf penulis CPPT) */
    public function signCppt(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['pin' => 'required|string']);

        try {
            $entry = $this->service->signCpptEntry($id, $validated['pin']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($entry, 'CPPT ditandatangani');
    }

    /** GET /perawat/cppt/visit/{visitId} — timeline descending */
    public function indexCppt(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getCpptTimeline($visitId));
    }

    // =========================================================================
    // PARALLEL STATUS
    // =========================================================================

    public function statusParallel(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getStatusParallel($visitId));
    }

    // =========================================================================
    // VITAL HISTORY
    // =========================================================================

    public function vitalHistory(string $patientId): JsonResponse
    {
        return $this->ok($this->service->getVitalHistory($patientId));
    }

    // =========================================================================
    // REKAM MEDIS PASIEN
    // =========================================================================

    public function rekamMedisPasien(string $patientId): JsonResponse
    {
        return $this->ok($this->service->getRekamMedisPasien($patientId));
    }

    public function showDokumen(string $documentId): JsonResponse
    {
        try {
            $doc = $this->service->getDokumen($documentId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 404);
        }

        return $this->ok($doc);
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

    private function error(string $message, int|string $status = 500): JsonResponse
    {
        // Coerce non-int status (e.g. PDO SQLSTATE string from QueryException) to a valid HTTP code.
        $status = (is_int($status) && $status >= 400 && $status < 600) ? $status : 500;
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
