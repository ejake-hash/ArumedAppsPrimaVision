<?php

namespace App\Http\Controllers;

use App\Services\BedahService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BedahController extends Controller
{
    public function __construct(private readonly BedahService $service) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    /** GET /bedah/antrian */
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
            $result = $this->service->selesaiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Antrian bedah selesai — pasien diteruskan');
    }

    // =========================================================================
    // JADWAL OPERASI
    // =========================================================================

    /**
     * GET /bedah/jadwal
     * Query params: tanggal, status
     */
    public function indexJadwal(Request $request): JsonResponse
    {
        return $this->ok($this->service->getScheduledSurgeries(
            $request->only(['tanggal', 'status', 'upcoming', 'date_from', 'date_to'])
        ));
    }

    /** GET /bedah/jadwal/{id} */
    public function showJadwal(string $id): JsonResponse
    {
        return $this->ok($this->service->getScheduleById($id));
    }

    /** POST /bedah/jadwal */
    public function storeJadwal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'surgery_package_id'  => 'required|uuid|exists:surgery_packages,id',
            'lead_surgeon_id'     => 'required|uuid|exists:employees,id',
            'anesthesiologist_id' => 'nullable|uuid|exists:employees,id',
            'scheduled_date'      => 'required|date|after_or_equal:today',
            'scheduled_time'      => 'required|date_format:H:i',
            'operation_room'      => 'nullable|string|max:100',
            'notes'               => 'nullable|string|max:500',
        ]);

        try {
            $schedule = $this->service->storeSchedule($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($schedule, 'Jadwal operasi dibuat', 201);
    }

    /** PUT /bedah/jadwal/{id} */
    public function updateJadwal(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'surgery_package_id'  => 'sometimes|uuid|exists:surgery_packages,id',
            'lead_surgeon_id'     => 'sometimes|uuid|exists:employees,id',
            'anesthesiologist_id' => 'nullable|uuid|exists:employees,id',
            'scheduled_date'      => 'sometimes|date',
            'scheduled_time'      => 'sometimes|date_format:H:i',
            'operation_room'      => 'nullable|string|max:100',
            'status'              => 'sometimes|in:SCHEDULED,CANCELLED',
            'notes'               => 'nullable|string|max:500',
        ]);

        try {
            $schedule = $this->service->updateSchedule($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($schedule, 'Jadwal diperbarui');
    }

    /** DELETE /bedah/jadwal/{id} */
    public function deleteJadwal(string $id): JsonResponse
    {
        try {
            $this->service->deleteSchedule($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Jadwal dihapus');
    }

    /**
     * PUT /bedah/jadwal/{id}/mulai
     * Time In — operasi dimulai.
     * Guard: supply request wajib sudah RECEIVED.
     */
    public function mulaiOperasi(string $id): JsonResponse
    {
        try {
            $record = $this->service->startOperation($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Operasi dimulai. Time In: ' . now()->format('H:i'));
    }

    /**
     * PUT /bedah/jadwal/{id}/selesai
     * Time Out + laporan operasi.
     */
    public function selesaiOperasi(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'operation_notes'      => 'nullable|string|max:5000',
            'has_complication'     => 'required|boolean',
            'complication_detail'  => 'required_if:has_complication,true|nullable|string|max:2000',
            'post_op_instructions' => 'nullable|string|max:2000',
            'followup_date'        => 'nullable|date|after_or_equal:today',
            // Disposisi pasca-op: PULANG → Kasir | RAWAT_INAP → papan Menunggu Kamar |
            // LANJUT_RANAP → kembali ke kamar (pasien sudah RANAP) | HCU → RANAP + tanda HCU.
            // Tanpa rule ini Laravel men-drop field → service selalu default PULANG.
            'post_op_disposition'  => 'nullable|in:PULANG,RAWAT_INAP,LANJUT_RANAP,HCU',
        ]);

        try {
            $record = $this->service->completeOperation($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        $warnings = $record->warnings ?? [];
        $message  = 'Operasi selesai. Time Out: ' . now()->format('H:i') . '. Lengkapi & kunci laporan untuk meneruskan pasien.';
        if (! empty($warnings)) {
            $message .= ' ⚠ ' . implode(' ', $warnings);
        }

        return $this->ok($record, $message);
    }

    /**
     * PUT /bedah/record/{id}/safety-checklist
     * Satu fase WHO Surgical Safety Checklist (sign_in/time_out/sign_out).
     * Gating lunak: bypass_reason diisi = fase dilewati darurat (tercatat audit).
     */
    public function saveSafetyChecklist(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'phase'         => 'required|in:sign_in,time_out,sign_out',
            'data'          => 'required|array',
            'bypass_reason' => 'nullable|string|max:500',
        ]);

        try {
            $record = $this->service->saveSafetyChecklist(
                $id,
                $validated['phase'],
                $validated['data'],
                $validated['bypass_reason'] ?? null,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Checklist keselamatan disimpan.');
    }

    /**
     * PUT /bedah/record/{id}/operation-report
     * Laporan operasi terstruktur (isi minimal PAB). Implan auto dari IOL terpasang.
     */
    public function saveOperationReport(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'diagnosis_pre'        => 'nullable|string|max:1000',
            'diagnosis_post'       => 'required|string|max:1000',
            'procedure_name'       => 'nullable|string|max:255',
            'operator'             => 'nullable|string|max:255',
            'asisten'              => 'nullable|array',
            'anesthesiologist'     => 'nullable|string|max:255',
            'anesthesia_type'      => 'nullable|string|max:100',
            'findings'             => 'nullable|string|max:5000',
            'technique'            => 'nullable|string|max:5000',
            'notes'                => 'nullable|string|max:5000',
            'complication'         => 'nullable|array',
            'estimated_blood_loss' => 'nullable|string|max:100',
            'specimens'            => 'nullable|array',
            'vitrectomy_details'   => 'nullable|array',
            'closure'              => 'nullable|string|max:1000',
            'post_op_disposition'  => 'nullable|in:PULANG,RAWAT_INAP,LANJUT_RANAP,HCU',
        ]);

        try {
            $record = $this->service->saveOperationReport($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Laporan operasi disimpan.');
    }

    /**
     * PUT /bedah/record/{id}/recovery-assessment
     * Skor pemulihan Aldrete + nyeri + vital (PACU). Total dihitung di server.
     */
    public function saveRecoveryAssessment(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'aldrete'                 => 'required|array',
            'aldrete.activity'        => 'required|integer|between:0,2',
            'aldrete.respiration'     => 'required|integer|between:0,2',
            'aldrete.circulation'     => 'required|integer|between:0,2',
            'aldrete.consciousness'   => 'required|integer|between:0,2',
            'aldrete.spo2'            => 'required|integer|between:0,2',
            'pain_score'              => 'nullable|integer|between:0,10',
            'vitals'                  => 'nullable|array',
            'notes'                   => 'nullable|string|max:1000',
        ]);

        try {
            $record = $this->service->saveRecoveryAssessment($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Skor pemulihan disimpan.');
    }

    // =========================================================================
    // ANESTESI — Laporan (RM 5.2) + Monitoring vital durante
    // =========================================================================

    /** GET /bedah/anesthesiologists — dropdown DPJP Anestesi (role dokter_anestesi). */
    public function anesthesiologists(): JsonResponse
    {
        return $this->ok($this->service->getAnesthesiologists());
    }

    /** GET /bedah/record/{id}/anesthesia */
    public function getAnesthesiaReport(string $id): JsonResponse
    {
        return $this->ok($this->service->getAnesthesiaReport($id));
    }

    /** POST /bedah/record/{id}/anesthesia — simpan/perbarui laporan (form_data JSONB). */
    public function saveAnesthesiaReport(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'form_data' => 'required|array',
        ]);

        try {
            $report = $this->service->saveAnesthesiaReport($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($report, 'Laporan anestesi disimpan.');
    }

    /** GET /bedah/record/{id}/anesthesia-vitals */
    public function listAnesthesiaVitals(string $id): JsonResponse
    {
        return $this->ok($this->service->listAnesthesiaVitals($id));
    }

    /** POST /bedah/anesthesia-vital — catat 1 pembacaan vital durante. */
    public function recordAnesthesiaVital(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'surgery_record_id' => 'required|uuid|exists:surgery_records,id',
            'recorded_at'       => 'nullable|date',
            'td_sistol'         => 'nullable|integer|between:0,300',
            'td_diastol'        => 'nullable|integer|between:0,200',
            'nadi'              => 'nullable|integer|between:0,300',
            'spo2'              => 'nullable|numeric|between:0,100',
            'rr'                => 'nullable|integer|between:0,100',
            'etco2'             => 'nullable|integer|between:0,150',
            'suhu'              => 'nullable|numeric|between:25,45',
            'obat_kejadian'     => 'nullable|string|max:500',
        ]);

        try {
            $vital = $this->service->recordAnesthesiaVital($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($vital, 'Vital dicatat.', 201);
    }

    /** PUT /bedah/anesthesia-vital/{id} */
    public function updateAnesthesiaVital(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'recorded_at'   => 'nullable|date',
            'td_sistol'     => 'nullable|integer|between:0,300',
            'td_diastol'    => 'nullable|integer|between:0,200',
            'nadi'          => 'nullable|integer|between:0,300',
            'spo2'          => 'nullable|numeric|between:0,100',
            'rr'            => 'nullable|integer|between:0,100',
            'etco2'         => 'nullable|integer|between:0,150',
            'suhu'          => 'nullable|numeric|between:25,45',
            'obat_kejadian' => 'nullable|string|max:500',
        ]);

        try {
            $vital = $this->service->updateAnesthesiaVital($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($vital, 'Vital diperbarui.');
    }

    /** DELETE /bedah/anesthesia-vital/{id} */
    public function destroyAnesthesiaVital(string $id): JsonResponse
    {
        try {
            $this->service->deleteAnesthesiaVital($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Vital dihapus.');
    }

    // =========================================================================
    // SURGERY RECORD
    // =========================================================================

    /** GET /bedah/record/{scheduleId} */
    public function showRecord(string $scheduleId): JsonResponse
    {
        return $this->ok($this->service->getRecord($scheduleId));
    }

    /** POST /bedah/record */
    public function storeRecord(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'surgery_schedule_id'  => 'required|uuid|exists:surgery_schedules,id',
            'visit_id'             => 'nullable|uuid|exists:visits,id',
            'time_in'              => 'required|date',
            'time_out'             => 'nullable|date|after:time_in',
            'operation_notes'      => 'nullable|string|max:5000',
            'has_complication'     => 'nullable|boolean',
            'complication_detail'  => 'required_if:has_complication,true|nullable|string|max:2000',
            'post_op_instructions' => 'nullable|string|max:2000',
            'followup_date'        => 'nullable|date',
        ]);

        try {
            $record = $this->service->recordSurgery($validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Laporan operasi dibuat', 201);
    }

    /** PUT /bedah/record/{id} */
    public function updateRecord(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'time_out'             => 'nullable|date',
            'operation_notes'      => 'nullable|string|max:5000',
            'has_complication'     => 'nullable|boolean',
            'complication_detail'  => 'required_if:has_complication,true|nullable|string|max:2000',
            'post_op_instructions' => 'nullable|string|max:2000',
            'followup_date'        => 'nullable|date',
        ]);

        try {
            $record = $this->service->updateRecord($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Laporan operasi diperbarui');
    }

    /**
     * PUT /bedah/record/{id}/post-op
     * Instruksi post-op + tanggal kontrol pasca bedah.
     */
    public function storePostOp(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'post_op_instructions' => 'required|string|max:2000',
            'followup_date'        => 'nullable|date|after_or_equal:today',
        ]);

        try {
            $record = $this->service->storePostOp($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Instruksi post-op disimpan');
    }

    /** POST /bedah/record/{id}/finalize */
    public function finalizeRecord(string $id): JsonResponse
    {
        try {
            $record = $this->service->finalizeRecord($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Laporan operasi dikunci. Pasien diteruskan ke Farmasi/Kasir.');
    }

    /**
     * POST /bedah/record/{id}/resep-pasca
     * Resep obat pasca-bedah (obat pulang) → Prescription SUBMITTED utk Farmasi.
     *
     * Visit di-resolve dari laporan operasi (surgery_records.visit_id NOT NULL,
     * di-set saat startOperation). Resep SUBMITTED otomatis muncul di Farmasi
     * via QueueService::nextAfterKasir — TIDAK perlu enqueue manual.
     *
     * Param route bernama {id} (lihat routes/api.php) → binding scalar Laravel
     * berdasar NAMA, jadi argumen WAJIB $id (bukan $recordId).
     */
    public function storePostOpPrescription(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'items'                  => 'required|array|min:1',
            'items.*.medication_id'  => 'required|uuid|exists:medications,id',
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.dose'           => 'nullable|string|max:100',
            'items.*.frequency'      => 'nullable|string|max:100',
            'items.*.route'          => 'nullable|string|max:100',
            'items.*.duration_days'  => 'nullable|integer|min:1',
            'items.*.notes'          => 'nullable|string|max:255',
            // Pos kwitansi per-baris (Obat Pulang/Tindakan/Injeksi) — dipilih operator.
            // NULL/kosong = ikut default master tarif obat.
            'items.*.pos_kwitansi'   => 'nullable|string|in:OBAT_PULANG,OBAT_TINDAKAN,OBAT_INJEKSI',
            // Penanda obat dari "paket obat" → kandidat terserap ke harga paket
            // (is_bedah di-set bersyarat di service bila pasien berpaket).
            'items.*.bundled'        => 'nullable|boolean',
            'pharmacy_note'          => 'nullable|string|max:500',
        ]);

        $record  = \App\Models\SurgeryRecord::findOrFail($id);
        $visitId = $record->visit_id;

        if (! $visitId) {
            return $this->error('Laporan tidak terhubung kunjungan', 422);
        }

        try {
            $resep = $this->service->storePostOpPrescription($visitId, $validated['items'], [
                'pharmacy_note' => $validated['pharmacy_note'] ?? null,
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($resep, 'Resep pasca-bedah dikirim ke Farmasi', 201);
    }

    /**
     * GET /bedah/record/{id}/resep-pasca
     * Muat resep pasca-bedah aktif + status tagihan → BedahView hidrasi daftar obat
     * saat buka pasien & gating "Buka Kembali" (revisi pra-bayar).
     */
    public function getPostOpPrescription(string $id): JsonResponse
    {
        $record  = \App\Models\SurgeryRecord::findOrFail($id);
        $visitId = $record->visit_id;

        if (! $visitId) {
            return $this->error('Laporan tidak terhubung kunjungan', 422);
        }

        return $this->ok($this->service->getPostOpPrescription($visitId));
    }

    // =========================================================================
    // PAKET OBAT PASCA-BEDAH (template resep rutin)
    // =========================================================================

    /** GET /bedah/paket-obat */
    public function indexPaketObat(Request $request): JsonResponse
    {
        return $this->ok($this->service->listPrescriptionTemplates($request->query('search')));
    }

    /** POST /bedah/paket-obat */
    public function storePaketObat(Request $request): JsonResponse
    {
        $validated = $this->validatePaketObat($request);

        return $this->ok($this->service->storePrescriptionTemplate($validated), 'Paket obat dibuat', 201);
    }

    /** PUT /bedah/paket-obat/{id} */
    public function updatePaketObat(Request $request, string $id): JsonResponse
    {
        $validated = $this->validatePaketObat($request, true);

        try {
            $tpl = $this->service->updatePrescriptionTemplate($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($tpl, 'Paket obat diperbarui');
    }

    /** DELETE /bedah/paket-obat/{id} */
    public function destroyPaketObat(string $id): JsonResponse
    {
        try {
            $this->service->deletePrescriptionTemplate($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Paket obat dihapus');
    }

    /** Validasi payload paket obat (store/update). */
    private function validatePaketObat(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name'                  => "{$req}|string|max:255",
            'category'              => 'nullable|string|max:100',
            'description'           => 'nullable|string|max:1000',
            'is_active'             => 'nullable|boolean',
            'items'                 => "{$req}|array|min:1",
            'items.*.medication_id' => 'required|uuid|exists:medications,id',
            'items.*.quantity'      => 'nullable|integer|min:1',
            'items.*.dose'          => 'nullable|string|max:100',
            'items.*.frequency'     => 'nullable|string|max:100',
            'items.*.route'         => 'nullable|string|max:100',
            'items.*.duration_days' => 'nullable|integer|min:1',
        ]);
    }

    // =========================================================================
    // SUPPLY REQUEST (BHP + IOL ke Farmasi)
    // =========================================================================

    /**
     * GET /bedah/request
     * Query params: status, tanggal
     */
    public function indexRequest(Request $request): JsonResponse
    {
        return $this->ok($this->service->getRequests($request->only(['status', 'tanggal'])));
    }

    /** GET /bedah/request/{id} */
    public function showRequest(string $id): JsonResponse
    {
        return $this->ok($this->service->getRequestById($id));
    }

    /**
     * POST /bedah/request
     * Body: {
     *   visit_id, surgery_schedule_id, notes,
     *   bhp_items: [{bhp_item_id, quantity, notes}],
     *   iol_items: [{eye_side, requested_iol_type, requested_power, notes}]
     * }
     */
    public function storeRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id'                           => 'required|uuid|exists:visits,id',
            'surgery_schedule_id'                => 'nullable|uuid|exists:surgery_schedules,id',
            'notes'                              => 'nullable|string|max:500',

            'bhp_items'                          => 'nullable|array',
            'bhp_items.*.bhp_item_id'            => 'required|uuid|exists:bhp_items,id',
            'bhp_items.*.quantity'               => 'required|integer|min:1',
            'bhp_items.*.notes'                  => 'nullable|string|max:255',

            'iol_items'                          => 'nullable|array',
            'iol_items.*.eye_side'               => 'required|in:OD,OS',
            'iol_items.*.requested_iol_type'     => 'nullable|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
            'iol_items.*.requested_power'        => 'required|numeric|between:0,40',
            'iol_items.*.notes'                  => 'nullable|string|max:255',
        ]);

        if (empty($validated['bhp_items']) && empty($validated['iol_items'])) {
            return $this->validationError(['items' => ['Minimal 1 item BHP atau IOL wajib diisi.']]);
        }

        try {
            $surgeryRequest = $this->service->createSupplyRequest($validated['visit_id'], $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($surgeryRequest, 'Request BHP+IOL dibuat', 201);
    }

    /** PUT /bedah/request/{id} */
    public function updateRequest(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes'                          => 'nullable|string|max:500',
            'bhp_items'                      => 'nullable|array',
            'bhp_items.*.bhp_item_id'        => 'required|uuid|exists:bhp_items,id',
            'bhp_items.*.quantity'           => 'required|integer|min:1',
            'bhp_items.*.notes'              => 'nullable|string|max:255',
            'iol_items'                      => 'nullable|array',
            'iol_items.*.eye_side'           => 'required|in:OD,OS',
            'iol_items.*.requested_iol_type' => 'nullable|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
            'iol_items.*.requested_power'    => 'required|numeric|between:0,40',
            'iol_items.*.notes'              => 'nullable|string|max:255',
        ]);

        try {
            $surgeryRequest = $this->service->updateRequest($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($surgeryRequest, 'Request diperbarui');
    }

    /** PUT /bedah/request/{id}/kirim */
    public function kirimRequest(string $id): JsonResponse
    {
        try {
            $surgeryRequest = $this->service->kirimRequest($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($surgeryRequest, 'Request BHP+IOL dikirim ke Farmasi');
    }

    /**
     * GET /bedah/jadwal/{id}/auto-request/preview
     * Preview isi request BHP/IOL dari komposisi paket bedah jadwal tsb.
     */
    public function previewAutoRequest(string $scheduleId): JsonResponse
    {
        try {
            $preview = $this->service->buildRequestPreviewFromSchedule($scheduleId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($preview);
    }

    /**
     * POST /bedah/jadwal/{id}/auto-request
     * 1-klik: buat request dari paket + kirim ke Bedah (REQUESTED → SENT).
     * Validasi longgar (IOL eye/power boleh kosong, beda dgn storeRequest).
     */
    public function sendAutoRequest(Request $request, string $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'bhp_items'                      => 'nullable|array',
            'bhp_items.*.bhp_item_id'        => 'required|uuid|exists:bhp_items,id',
            'bhp_items.*.quantity'           => 'required|integer|min:1',

            'iol_items'                      => 'nullable|array',
            'iol_items.*.eye_side'           => 'nullable|in:OD,OS',
            'iol_items.*.requested_iol_type' => 'nullable|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
            'iol_items.*.requested_power'    => 'nullable|numeric|between:0,40',
            'iol_items.*.iol_item_id'        => 'nullable|uuid|exists:iol_items,id',
        ]);

        try {
            $surgeryRequest = $this->service->sendRequestFromSchedule($scheduleId, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($surgeryRequest, 'Request BHP/IOL terkirim ke Bedah', 201);
    }

    /**
     * PUT /bedah/request/{id}/terima
     * Konfirmasi terima BHP+IOL dari Farmasi.
     */
    public function terimaRequest(string $id): JsonResponse
    {
        try {
            $surgeryRequest = $this->service->terimaRequest($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($surgeryRequest, 'BHP+IOL dikonfirmasi diterima. Siap operasi.');
    }

    /**
     * POST /bedah/request/{id}/adjust-bhp
     * Body: { items: [{ bhp_item_id, used_qty }] }
     *
     * Bedah catat qty actual BHP yang terpakai. Boleh ± dari quantity yg diminta.
     */
    public function adjustBhpUsage(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.bhp_item_id'=> 'required|uuid',
            'items.*.used_qty'   => 'required|integer|min:0',
        ]);

        try {
            $surgeryRequest = $this->service->adjustBhpUsage($id, $data['items']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($surgeryRequest, 'BHP usage diperbarui.');
    }

    // =========================================================================
    // KOMPONEN PAKET PASIEN (snapshot) — edit BHP & Tindakan
    // =========================================================================

    /** GET /bedah/visit-package/{visitId} — SEMUA paket pasien + komponen (multi-paket). */
    public function getVisitPackage(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getVisitPackages($visitId));
    }

    /** POST /bedah/visit-package/{visitId}/package — tambah paket (mis. anestesi TIVA). */
    public function addVisitPackage(Request $request, string $visitId): JsonResponse
    {
        $data = $request->validate([
            'package_id' => 'required|uuid',
            'tariff_id'  => 'nullable|uuid|exists:surgery_package_tariffs,id',
        ]);

        try {
            $result = $this->service->addVisitPackage($visitId, $data['package_id'], $data['tariff_id'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Paket ditambahkan');
    }

    /** DELETE /bedah/visit-package/{snapshotId} — hapus satu paket dari pasien. */
    public function removeVisitPackage(string $snapshotId): JsonResponse
    {
        try {
            $result = $this->service->removeVisitPackage($snapshotId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Paket dihapus');
    }

    /** POST /bedah/visit-package/{visitId}/items — tambah komponen (PROCEDURE/BHP). */
    public function addVisitPackageItem(Request $request, string $visitId): JsonResponse
    {
        $data = $request->validate([
            'item_type'  => 'required|in:PROCEDURE,BHP',
            'item_id'    => 'required|uuid',
            'quantity'   => 'nullable|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'notes'      => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->service->addVisitPackageItem($visitId, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Komponen paket ditambah');
    }

    /** PUT /bedah/visit-package-item/{itemId} — ubah qty/harga/notes. */
    public function updateVisitPackageItem(Request $request, string $itemId): JsonResponse
    {
        $data = $request->validate([
            'quantity'   => 'nullable|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'notes'      => 'nullable|string|max:255',
        ]);

        try {
            $result = $this->service->updateVisitPackageItem($itemId, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Komponen paket diperbarui');
    }

    /** DELETE /bedah/visit-package-item/{itemId} — hapus komponen. */
    public function removeVisitPackageItem(string $itemId): JsonResponse
    {
        try {
            $result = $this->service->removeVisitPackageItem($itemId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Komponen paket dihapus');
    }

    // =========================================================================
    // IOL USAGE
    // =========================================================================

    /** GET /bedah/iol-usage?surgery_record_id=… — daftar IOL terpasang. */
    public function indexIolUsage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'surgery_record_id' => 'required|uuid|exists:surgery_records,id',
        ]);

        return $this->ok($this->service->listIolUsage($validated['surgery_record_id']));
    }

    /**
     * POST /bedah/iol-usage
     * Body: { surgery_record_id, iol_item_id?, eye_side, brand?, model?, power?,
     *         lot_number?, serial_number?, gtin?, gs1_barcode?, expiry_date? }
     *
     * iol_item_id NULLABLE: lensa non-master (belum terdaftar) tetap boleh dicatat
     * (keputusan "peringatkan, bukan tolak"). Service mengembalikan warnings[].
     */
    public function storeIolUsage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'surgery_record_id' => 'required|uuid|exists:surgery_records,id',
            'iol_item_id'       => 'nullable|uuid|exists:iol_items,id',
            'eye_side'          => 'required|in:OD,OS',
            'brand'             => 'nullable|string|max:100',
            'model'             => 'nullable|string|max:100',
            'power'             => 'nullable|numeric|between:-20,40',
            'lot_number'        => 'nullable|string|max:100',
            'serial_number'     => 'nullable|string|max:100',
            'gtin'              => 'nullable|string|max:14',
            'gs1_barcode'       => 'nullable|string|max:512',
            'expiry_date'       => 'nullable|date',
        ]);

        try {
            $result = $this->service->recordIolUsage($validated['surgery_record_id'], $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        $msg = empty($result['warnings'])
            ? 'Pemakaian IOL dicatat'
            : 'Pemakaian IOL dicatat (dengan peringatan)';

        return $this->ok($result, $msg, 201);
    }

    /** PUT /bedah/iol-usage/{id} */
    public function updateIolUsage(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'eye_side'      => 'nullable|in:OD,OS',
            'brand'         => 'nullable|string|max:100',
            'model'         => 'nullable|string|max:100',
            'power'         => 'nullable|numeric|between:-20,40',
            'lot_number'    => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
        ]);

        try {
            $usage = $this->service->updateIolUsage($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($usage, 'Pemakaian IOL diperbarui');
    }

    /** DELETE /bedah/iol-usage/{id} — hapus + kembalikan stok. */
    public function destroyIolUsage(string $id): JsonResponse
    {
        try {
            $this->service->deleteIolUsage($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Catatan IOL dihapus, stok dikembalikan');
    }

    // =========================================================================
    // MASTER LOOKUP (Bedah-scoped)
    // =========================================================================

    /**
     * GET /bedah/obat
     * Daftar obat utk resep pasca-bedah. Shape sama dgn dokterApi.daftarObat
     * (id, code, name, form, golongan, unit, stock, hja).
     */
    public function daftarObat(Request $request): JsonResponse
    {
        return $this->ok($this->service->getDaftarObat($request->query('search')));
    }

    /**
     * GET /bedah/iol
     * Daftar IOL item (lensa tanam) utk pencatatan pemakaian saat operasi.
     */
    public function indexIol(Request $request): JsonResponse
    {
        return $this->ok($this->service->getIolItems(
            $request->only(['search', 'iol_type', 'material', 'active', 'available_only', 'per_page'])
        ));
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

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => 'Validasi gagal',
            'errors'  => $errors,
        ], 422);
    }
}
