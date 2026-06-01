<?php

namespace App\Http\Controllers;

use App\Services\RefraksiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefraksiController extends Controller
{
    public function __construct(private readonly RefraksiService $service) {}

    // =========================================================================
    // ANTRIAN REFRAKSIONIS
    // =========================================================================

    /**
     * GET /refraksi/antrian
     * List semua pasien di queue REFRAKSIONIS hari ini.
     */
    public function indexAntrian(): JsonResponse
    {
        return $this->ok($this->service->getPatientQueue());
    }

    /**
     * GET /refraksi/kunjungan/{visitId}
     * Detail pasien + status rekam refraksi saat ini.
     */
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

        return $this->ok($result, 'Antrian refraksi selesai');
    }

    /** PUT /refraksi/antrian/{id}/mulai — CALLED → IN_PROGRESS */
    public function mulaiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->mulaiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien sedang dilayani');
    }

    /** PUT /refraksi/antrian/{id}/lewati — pindah pasien ke akhir antrean */
    public function lewatiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->lewatiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dilewati');
    }

    // =========================================================================
    // REFRACTION RECORD
    // =========================================================================

    /**
     * GET /refraksi/pemeriksaan/{visitId}
     */
    public function showPemeriksaan(string $visitId): JsonResponse
    {
        $record = $this->service->getRefractionRecord($visitId);

        // Repopulate tiket Dokter (D-NNN) saat pasien yang sudah finalized dipilih ulang,
        // supaya tombol "Cetak Tiket Dokter" tetap aktif (cetak ulang). Null kalau partner
        // TR belum selesai — getDoctorTicket() hanya menemukan antrian DOKTER bila gate lolos.
        if ($record?->is_finalized) {
            $record->doctor_ticket = $this->service->doctorTicket($visitId);
        }

        return $this->ok($record);
    }

    /**
     * POST /refraksi/pemeriksaan
     * Simpan data refraksi OD/OS. Satu kunjungan = satu record.
     */
    public function storePemeriksaan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id'        => 'required|uuid|exists:visits,id',
            'perception_type' => 'required|in:DEKAT,JAUH',
            'examination_date' => 'nullable|date',

            // Autoref OD/OS
            'autoref_od_sph'  => 'nullable|numeric|between:-30,30',
            'autoref_od_cyl'  => 'nullable|numeric|between:-15,15',
            'autoref_od_axis' => 'nullable|integer|between:0,180',
            'autoref_os_sph'  => 'nullable|numeric|between:-30,30',
            'autoref_os_cyl'  => 'nullable|numeric|between:-15,15',
            'autoref_os_axis' => 'nullable|integer|between:0,180',

            // Keratometri OD/OS
            'keratometri1_od'     => 'nullable|numeric|between:30,60',
            'keratometri2_od'     => 'nullable|numeric|between:30,60',
            'keratometri_axis_od' => 'nullable|integer|between:0,180',
            'keratometri1_os'     => 'nullable|numeric|between:30,60',
            'keratometri2_os'     => 'nullable|numeric|between:30,60',
            'keratometri_axis_os' => 'nullable|integer|between:0,180',

            // Visus OD/OS (string: 6/6, 1/60, HM, LP, NLP)
            'visus_awal_od'  => 'nullable|string|max:20',
            'visus_akhir_od' => 'nullable|string|max:20',
            'pinhole_od'     => 'nullable|string|max:20',
            'add_power_od'   => 'nullable|numeric|between:0,5',
            'visus_awal_os'  => 'nullable|string|max:20',
            'visus_akhir_os' => 'nullable|string|max:20',
            'pinhole_os'     => 'nullable|string|max:20',
            'add_power_os'   => 'nullable|numeric|between:0,5',

            // Refraksi Subjektif OD/OS
            'refraksi_subjektif_od_sph'  => 'nullable|numeric|between:-30,30',
            'refraksi_subjektif_od_cyl'  => 'nullable|numeric|between:-15,15',
            'refraksi_subjektif_od_axis' => 'nullable|integer|between:0,180',
            'refraksi_subjektif_os_sph'  => 'nullable|numeric|between:-30,30',
            'refraksi_subjektif_os_cyl'  => 'nullable|numeric|between:-15,15',
            'refraksi_subjektif_os_axis' => 'nullable|integer|between:0,180',

            // Kacamata Lama OD/OS
            'old_glasses_od_sph'  => 'nullable|numeric|between:-30,30',
            'old_glasses_od_cyl'  => 'nullable|numeric|between:-15,15',
            'old_glasses_od_axis' => 'nullable|integer|between:0,180',
            'old_glasses_add_od'  => 'nullable|numeric|between:0,5',
            'old_glasses_os_sph'  => 'nullable|numeric|between:-30,30',
            'old_glasses_os_cyl'  => 'nullable|numeric|between:-15,15',
            'old_glasses_os_axis' => 'nullable|integer|between:0,180',
            'old_glasses_add_os'  => 'nullable|numeric|between:0,5',

            // IOP
            'iop_od'     => 'nullable|numeric|between:0,80',
            'iop_os'     => 'nullable|numeric|between:0,80',
            'iop_method' => 'nullable|in:NCT,Goldmann,Schiotz',

            // Shared
            'pd_distance'    => 'nullable|numeric|between:40,80',
            'clinical_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $record = $this->service->storeRefractionRecord($validated['visit_id'], $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Data refraksi berhasil disimpan', 201);
    }

    /**
     * PUT /refraksi/pemeriksaan/{id}
     */
    public function updatePemeriksaan(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'perception_type'  => 'sometimes|in:DEKAT,JAUH',
            'examination_date' => 'nullable|date',

            'autoref_od_sph'  => 'nullable|numeric|between:-30,30',
            'autoref_od_cyl'  => 'nullable|numeric|between:-15,15',
            'autoref_od_axis' => 'nullable|integer|between:0,180',
            'autoref_os_sph'  => 'nullable|numeric|between:-30,30',
            'autoref_os_cyl'  => 'nullable|numeric|between:-15,15',
            'autoref_os_axis' => 'nullable|integer|between:0,180',

            'keratometri1_od'     => 'nullable|numeric|between:30,60',
            'keratometri2_od'     => 'nullable|numeric|between:30,60',
            'keratometri_axis_od' => 'nullable|integer|between:0,180',
            'keratometri1_os'     => 'nullable|numeric|between:30,60',
            'keratometri2_os'     => 'nullable|numeric|between:30,60',
            'keratometri_axis_os' => 'nullable|integer|between:0,180',

            'visus_awal_od'  => 'nullable|string|max:20',
            'visus_akhir_od' => 'nullable|string|max:20',
            'pinhole_od'     => 'nullable|string|max:20',
            'add_power_od'   => 'nullable|numeric|between:0,5',
            'visus_awal_os'  => 'nullable|string|max:20',
            'visus_akhir_os' => 'nullable|string|max:20',
            'pinhole_os'     => 'nullable|string|max:20',
            'add_power_os'   => 'nullable|numeric|between:0,5',

            'refraksi_subjektif_od_sph'  => 'nullable|numeric|between:-30,30',
            'refraksi_subjektif_od_cyl'  => 'nullable|numeric|between:-15,15',
            'refraksi_subjektif_od_axis' => 'nullable|integer|between:0,180',
            'refraksi_subjektif_os_sph'  => 'nullable|numeric|between:-30,30',
            'refraksi_subjektif_os_cyl'  => 'nullable|numeric|between:-15,15',
            'refraksi_subjektif_os_axis' => 'nullable|integer|between:0,180',

            'old_glasses_od_sph'  => 'nullable|numeric|between:-30,30',
            'old_glasses_od_cyl'  => 'nullable|numeric|between:-15,15',
            'old_glasses_od_axis' => 'nullable|integer|between:0,180',
            'old_glasses_add_od'  => 'nullable|numeric|between:0,5',
            'old_glasses_os_sph'  => 'nullable|numeric|between:-30,30',
            'old_glasses_os_cyl'  => 'nullable|numeric|between:-15,15',
            'old_glasses_os_axis' => 'nullable|integer|between:0,180',
            'old_glasses_add_os'  => 'nullable|numeric|between:0,5',

            'iop_od'     => 'nullable|numeric|between:0,80',
            'iop_os'     => 'nullable|numeric|between:0,80',
            'iop_method' => 'nullable|in:NCT,Goldmann,Schiotz',

            'pd_distance'    => 'nullable|numeric|between:40,80',
            'clinical_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $record = $this->service->updateRefractionRecord($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Data refraksi diperbarui');
    }

    /**
     * POST /refraksi/pemeriksaan/{id}/finalize
     * Kunci data refraksi → trigger parallel check.
     */
    public function finalizePemeriksaan(string $id): JsonResponse
    {
        try {
            $record = $this->service->finalizeRefraction($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        // Sertakan tiket Dokter (D-NNN) bila gate TR sudah lolos & antrian DOKTER dibuat.
        // Frontend (RefraksionisView) memunculkan tombol "Cetak Tiket Dokter" bila ada.
        $record->doctor_ticket = $this->service->doctorTicket($record->visit_id);

        return $this->ok($record, 'Data refraksi dikunci. Tidak bisa diubah.');
    }

    // =========================================================================
    // RESEP KACAMATA
    // =========================================================================

    /**
     * GET /refraksi/resep-kacamata/{refractionId}
     */
    public function showResepKacamata(string $refractionId): JsonResponse
    {
        return $this->ok($this->service->getRefractionPrescription($refractionId));
    }

    /**
     * POST /refraksi/resep-kacamata
     */
    public function storeResepKacamata(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refraction_record_id' => 'required|uuid|exists:refraction_records,id',

            'rx_od_sph'  => 'nullable|numeric|between:-30,30',
            'rx_od_cyl'  => 'nullable|numeric|between:-15,15',
            'rx_od_axis' => 'nullable|integer|between:0,180',
            'rx_od_add'  => 'nullable|numeric|between:0,5',
            'rx_os_sph'  => 'nullable|numeric|between:-30,30',
            'rx_os_cyl'  => 'nullable|numeric|between:-15,15',
            'rx_os_axis' => 'nullable|integer|between:0,180',
            'rx_os_add'  => 'nullable|numeric|between:0,5',

            'glasses_type'  => 'nullable|string|max:100',
            'lens_material' => 'nullable|string|max:100',
            'coating'       => 'nullable|string|max:100',
            'notes'         => 'nullable|string|max:500',
        ]);

        try {
            $prescription = $this->service->storeRefractionPrescription(
                $validated['refraction_record_id'],
                $validated
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Resep kacamata disimpan', 201);
    }

    /**
     * PUT /refraksi/resep-kacamata/{id}
     */
    public function updateResepKacamata(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'rx_od_sph'  => 'nullable|numeric|between:-30,30',
            'rx_od_cyl'  => 'nullable|numeric|between:-15,15',
            'rx_od_axis' => 'nullable|integer|between:0,180',
            'rx_od_add'  => 'nullable|numeric|between:0,5',
            'rx_os_sph'  => 'nullable|numeric|between:-30,30',
            'rx_os_cyl'  => 'nullable|numeric|between:-15,15',
            'rx_os_axis' => 'nullable|integer|between:0,180',
            'rx_os_add'  => 'nullable|numeric|between:0,5',
            'glasses_type'  => 'nullable|string|max:100',
            'lens_material' => 'nullable|string|max:100',
            'coating'       => 'nullable|string|max:100',
            'notes'         => 'nullable|string|max:500',
        ]);

        try {
            $prescription = $this->service->updateRefractionPrescription($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Resep kacamata diperbarui');
    }

    // =========================================================================
    // IOL REKOMENDASI
    // =========================================================================

    public function showIolRekomendasi(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getIolRekomendasi($visitId));
    }

    public function storeIolRekomendasi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id'             => 'required|uuid|exists:visits,id',
            'diagnostic_result_id' => 'nullable|uuid|exists:diagnostic_results,id',
            'eye_side'             => 'required|in:OD,OS,OU',
            'recommended_power'    => 'required|numeric|between:0,40',
            'iol_type'             => 'nullable|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
            'brand'                => 'nullable|string|max:100',
            'notes'                => 'nullable|string|max:500',
        ]);

        try {
            $rekomendasi = $this->service->storeIolRekomendasi($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($rekomendasi, 'Rekomendasi IOL disimpan', 201);
    }

    public function updateIolRekomendasi(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'eye_side'          => 'sometimes|in:OD,OS,OU',
            'recommended_power' => 'sometimes|numeric|between:0,40',
            'iol_type'          => 'nullable|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
            'brand'             => 'nullable|string|max:100',
            'notes'             => 'nullable|string|max:500',
        ]);

        try {
            $rekomendasi = $this->service->updateIolRekomendasi($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($rekomendasi, 'Rekomendasi IOL diperbarui');
    }

    // =========================================================================
    // RIWAYAT & STATUS PARALLEL
    // =========================================================================

    /**
     * GET /refraksi/pasien/{patientId}/riwayat
     * Riwayat 10 rekam refraksi terakhir pasien.
     */
    public function riwayatRefraksi(string $patientId): JsonResponse
    {
        return $this->ok($this->service->getRiwayatRefraksi($patientId));
    }

    /**
     * GET /refraksi/kunjungan/{visitId}/status-parallel
     */
    public function statusParallel(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getStatusParallel($visitId));
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
