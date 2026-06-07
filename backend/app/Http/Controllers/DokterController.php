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

    /**
     * POST /dokter/verify-pin
     * Verifikasi PIN tanda tangan dokter yang sedang login.
     */
    public function verifyPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pin' => 'required|string',
        ]);

        $user = $request->user();
        $pin  = $user?->pin;

        if (! $pin) {
            return $this->error('PIN belum diatur. Hubungi admin untuk mengatur PIN di Data Pengguna.', 422);
        }

        if (! hash_equals((string) $pin, (string) $validated['pin'])) {
            return $this->error('PIN salah.', 422);
        }

        return $this->ok(['verified' => true], 'PIN valid');
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

    public function lewatiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->lewatiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien diturunkan 1 antrean');
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

    /** PUT /dokter/antrian/{id}/ke-penunjang */
    public function kirimKePenunjang(string $id): JsonResponse
    {
        try {
            $queue = $this->service->kirimKePenunjang($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dikirim ke pemeriksaan penunjang');
    }

    // =========================================================================
    // RUJUKAN INTERNAL ANTAR-POLI
    // =========================================================================

    /** GET /dokter/kunjungan/{visitId}/rujuk-internal/targets */
    public function rujukInternalTargets(string $visitId): JsonResponse
    {
        try {
            $targets = $this->service->getRujukInternalTargets($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($targets);
    }

    /** POST /dokter/kunjungan/{visitId}/rujuk-internal */
    public function rujukInternal(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'target_schedule_id' => 'required|uuid|exists:doctor_schedules,id',
            'reason'             => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->service->rujukInternal(
                $visitId,
                $validated['target_schedule_id'],
                $validated['reason'] ?? null
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        $msg = $result['enqueued']
            ? 'Pasien dirujuk & masuk antrean dokter tujuan hari ini.'
            : 'Rujukan dibuat. Pasien daftar ulang di hari praktik dokter tujuan.';

        return $this->ok($result, $msg, 201);
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

    /** GET /dokter/tarif-tindakan?visit_id=… — daftar tindakan + tarif per metode bayar */
    public function tarifTindakan(Request $request): JsonResponse
    {
        $request->validate(['visit_id' => 'required|uuid|exists:visits,id']);

        return $this->ok($this->service->getTarifTindakan($request->query('visit_id')));
    }

    /** GET /dokter/obat?search=… — daftar obat ber-harga (inventori farmasi) */
    public function daftarObat(Request $request): JsonResponse
    {
        return $this->ok($this->service->getDaftarObat($request->query('search')));
    }

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
            'services'                  => 'present|array',
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

    /**
     * POST /dokter/kunjungan/{visitId}/apply-package
     * Terapkan paket PEMERIKSAAN: merge tindakan paket ke visitServices + snapshot diskon.
     * Body: { package_id }
     */
    public function applyExaminationPackage(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => 'required|uuid|exists:surgery_packages,id',
        ]);

        try {
            $result = $this->service->applyExaminationPackage($visitId, $validated['package_id']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Paket pemeriksaan diterapkan');
    }

    /** DELETE /dokter/kunjungan/{visitId}/package — lepas paket pemeriksaan (snapshot dibuang). */
    public function removeExaminationPackage(string $visitId): JsonResponse
    {
        try {
            $this->service->removeExaminationPackage($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Paket pemeriksaan dilepas');
    }

    /** GET /dokter/kunjungan/{visitId}/resep */
    public function indexResep(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getPrescriptions($visitId));
    }

    /**
     * POST /dokter/kunjungan/{visitId}/resep
     * Body: { notes, pharmacy_note, items: [{medication_id, quantity, dose, frequency, route, duration_days, notes}] }
     */
    public function storeResep(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'notes'                       => 'nullable|string|max:500',
            'pharmacy_note'               => 'nullable|string|max:500',
            'items'                       => 'present|array',
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

    /**
     * GET /dokter/bedah/slot?tanggal=YYYY-MM-DD
     * Preview ringkas jadwal bedah pada tanggal tertentu (untuk Tab 4 → Jadwalkan Bedah):
     * total terjadwal + daftar jam yang sudah terisi. Tidak menarik relasi berat.
     */
    public function bedahSlot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tanggal'       => 'required|date',
            'location_type' => 'nullable|in:RUANG_BEDAH,RUANG_TINDAKAN',
        ]);

        return $this->ok($this->service->getBedahSlot(
            $validated['tanggal'],
            $validated['location_type'] ?? null
        ));
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
            // Diagnosa naratif (teks bebas) saat dokter ragu kode ICD-10.
            'diagnosis_text'     => 'nullable|string|max:1000',
            'tindakan_codes'     => 'nullable|array',
            'tindakan_codes.*'   => 'string|max:20',
            'planning'           => 'required|in:PULANG_BEROBAT_JALAN,BEDAH,RUJUK,RAWAT_INAP',
            'surgery_package_id' => 'nullable|uuid|exists:surgery_packages,id',
            'surgery_schedule_id' => 'nullable|uuid|exists:surgery_schedules,id',
            // Lokasi pelaksanaan bedah: RUANG_BEDAH (operasi) | RUANG_TINDAKAN (laser YAG/PRP).
            'location_type'      => 'nullable|in:RUANG_BEDAH,RUANG_TINDAKAN',
            'surgery_date'       => 'nullable|date|after_or_equal:today',
            'surgery_time'       => 'nullable|string|max:8',
            'operation_room'     => 'nullable|string|max:60',
            // Fase 8: BEDAH yang butuh inap pra-operasi (PRE_OP). Dibaca applyInpatientReason.
            'requires_inpatient' => 'nullable|boolean',

            // Rujukan EKSTERNAL non-BPJS (faskes lain) — disimpan ke RME & resume.
            'external_referral_facility' => 'nullable|string|max:255',
            'external_referral_reason'   => 'nullable|string|max:500',

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
            // Nama/jenis pemeriksaan diambil dari master diagnostic_test_types (atau "Lainnya").
            'test_type' => 'required|string|max:150',
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

    /** GET /dokter/kunjungan/{visitId}/biometri-iol — biometri + tabel IOL + master + keputusan */
    public function showBiometriIol(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getBiometriIol($visitId));
    }

    /** POST /dokter/kunjungan/{visitId}/keputusan-iol — simpan keputusan IOL dokter (per mata) */
    public function decideIol(string $visitId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'eye_side'             => 'required|in:OD,OS',
            'iol_item_id'          => 'nullable|uuid|exists:iol_items,id',
            'diagnostic_result_id' => 'nullable|uuid',
            'recommended_power'    => 'nullable|numeric|between:-40,60',
            'formula'              => 'nullable|string|max:30',
            'a_constant'           => 'nullable|numeric|between:90,130',
            'target_refraction'    => 'nullable|numeric|between:-20,20',
            'predicted_refraction' => 'nullable|numeric|between:-20,20',
            'iol_type'             => 'nullable|string|max:20',
            'brand'                => 'nullable|string|max:255',
            'notes'                => 'nullable|string|max:500',
        ]);

        try {
            $rec = $this->service->decideIol($visitId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($rec, 'Keputusan IOL tersimpan');
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
            // Field formulir Resume Medis Rawat Jalan (RM 1.7) — bag. yang diedit dokter.
            'rmrj_data'                        => 'nullable|array',
            'rmrj_data.anamnese'               => 'nullable|string|max:5000',
            'rmrj_data.pemeriksaan_fisik'      => 'nullable|string|max:5000',
            'rmrj_data.alergi_obat'            => 'nullable|string|max:2000',
            'rmrj_data.hasil_penunjang'        => 'nullable|string|max:5000',
            'rmrj_data.diagnosa'               => 'nullable|string|max:5000',
            'rmrj_data.tindakan'               => 'nullable|string|max:5000',
            'rmrj_data.terapi'                 => 'nullable|string|max:5000',
            'rmrj_data.riwayat_inap_operasi'   => 'nullable|string|max:5000',
            'rmrj_data.instruksi_edukasi'      => 'nullable|string|max:5000',
            'rmrj_data.kontrol_tanggal'        => 'nullable|string|max:30',
            'rmrj_data.kontrol_tempat'         => 'nullable|string|max:255',
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
            'poli_rujukan'       => 'nullable|string|max:20',
            'poli_rujukan_nama'  => 'nullable|string|max:150',
            'tipe_rujukan'       => 'nullable|in:0,1,2',   // 0 penuh, 1 partial, 2 rujuk balik
            'jns_pelayanan'      => 'nullable|in:1,2',      // 1 R.Inap, 2 R.Jalan
            'tgl_rujukan'        => 'nullable|date',
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

        $msg = $rujukan->status === 'SUCCESS'
            ? "Rujukan BPJS terbit. No: {$rujukan->no_rujukan}"
            : 'Surat rujukan dibuat.';

        return $this->ok($rujukan, $msg, 201);
    }

    /** GET /dokter/kunjungan/{visitId}/surat-kontrol — status SK BPJS visit ini */
    public function getSuratKontrol(string $visitId): JsonResponse
    {
        try {
            $letter = $this->service->getSuratKontrol($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($letter);
    }

    /** POST /dokter/kunjungan/{visitId}/surat-kontrol/submit — terbitkan ke VClaim */
    public function submitSuratKontrol(string $visitId): JsonResponse
    {
        try {
            $letter = $this->service->submitSuratKontrol($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($letter, "Surat Kontrol terbit. No: {$letter->no_surat_kontrol}");
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

    private function error(string $message, int|string $status = 500): JsonResponse
    {
        // Guard: pemanggil sering mengoper $e->getCode(), yang untuk QueryException
        // adalah SQLSTATE STRING (mis. '23502') — BUKAN kode HTTP valid. Status non-int
        // atau di luar rentang 100–599 di-clamp ke 500 supaya tidak melempar
        // TypeError/InvalidArgumentException yang menutupi pesan asli.
        if (!is_int($status) || $status < 100 || $status > 599) {
            $status = 500;
        }

        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
