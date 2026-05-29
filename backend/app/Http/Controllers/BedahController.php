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
        ]);

        try {
            $record = $this->service->completeOperation($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($record, 'Operasi selesai. Time Out: ' . now()->format('H:i') . '. Pasien diteruskan ke Farmasi.');
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

        return $this->ok($record, 'Laporan operasi dikunci');
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
    // IOL USAGE
    // =========================================================================

    /**
     * POST /bedah/iol-usage
     * Body: { surgery_record_id, iol_item_id, eye_side, brand, model, power, lot_number, serial_number }
     */
    public function storeIolUsage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'surgery_record_id' => 'required|uuid|exists:surgery_records,id',
            'iol_item_id'       => 'required|uuid|exists:iol_items,id',
            'eye_side'          => 'required|in:OD,OS',
            'brand'             => 'nullable|string|max:100',
            'model'             => 'nullable|string|max:100',
            'power'             => 'nullable|numeric|between:0,40',
            'lot_number'        => 'nullable|string|max:100',
            'serial_number'     => 'nullable|string|max:100',
        ]);

        try {
            $usage = $this->service->recordIolUsage($validated['surgery_record_id'], $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($usage, 'Pemakaian IOL dicatat', 201);
    }

    /** PUT /bedah/iol-usage/{id} */
    public function updateIolUsage(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'brand'         => 'nullable|string|max:100',
            'model'         => 'nullable|string|max:100',
            'power'         => 'nullable|numeric|between:0,40',
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
