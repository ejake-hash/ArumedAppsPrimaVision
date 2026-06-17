<?php

namespace App\Http\Controllers;

use App\Models\DiagnosticTestType;
use App\Services\PenunjangService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PenunjangController extends Controller
{
    public function __construct(private readonly PenunjangService $service) {}

    // =========================================================================
    // ANTRIAN PENUNJANG
    // =========================================================================

    /** GET /penunjang/antrian?tanggal=Y-m-d (default: papan live hari ini) */
    public function indexAntrian(Request $request): JsonResponse
    {
        return $this->ok($this->service->getPatientQueue($request->query('tanggal')));
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

    /** PUT /penunjang/antrian/{id}/lewati */
    public function lewatiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->lewatiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dipindah ke akhir antrean');
    }

    // =========================================================================
    // ORDER PENUNJANG
    // =========================================================================

    /**
     * GET /penunjang/order
     * Query params: tanggal, status, test_type
     */
    public function indexOrder(Request $request): JsonResponse
    {
        return $this->ok($this->service->getOrders($request->only(['tanggal', 'status', 'test_type'])));
    }

    /** GET /penunjang/order/{id} */
    public function showOrder(string $id): JsonResponse
    {
        return $this->ok($this->service->getOrderById($id));
    }

    /**
     * POST /penunjang/order (buat order baru dari sisi penunjang)
     * Body: { visit_id, test_type, eye_side, notes }
     */
    public function storeOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id'  => 'required|uuid|exists:visits,id',
            // test_type = kode master penunjang (mis. OCT, USG, BIOM). Konsisten dgn alur dokter.
            'test_type' => 'required|string|exists:diagnostic_test_types,code',
            'eye_side'  => 'nullable|in:OD,OS,OU',
            'notes'     => 'nullable|string|max:500',
        ]);

        try {
            $order = $this->service->createOrder(
                $validated['visit_id'],
                $validated['test_type'],
                $validated
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($order, 'Order penunjang dibuat', 201);
    }

    /** PUT /penunjang/order/{id}/proses */
    public function prosesOrder(string $id): JsonResponse
    {
        try {
            $order = $this->service->prosesOrder($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($order, 'Order sedang diproses');
    }

    /** PUT /penunjang/order/{id}/cancel */
    public function cancelOrder(string $id): JsonResponse
    {
        try {
            $this->service->cancelOrder($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Order dibatalkan');
    }

    // =========================================================================
    // HASIL PENUNJANG
    // =========================================================================

    /** GET /penunjang/hasil/{orderId} */
    public function showHasil(string $orderId): JsonResponse
    {
        return $this->ok($this->service->getResult($orderId));
    }

    /**
     * POST /penunjang/hasil
     *
     * expertise_data structure per test_type:
     *
     * OCT:       { findings, measurements: { retinal_thickness_od, retinal_thickness_os } }
     * USG:       { axial_length_od, axial_length_os, findings }
     * Biometri:  { od: { axial_length, k1, k2, acd, recommended_iol_power, iol_type, brand },
     *              os: { ... } }
     * Topografi: { findings, irregularity_od, irregularity_os }
     */
    public function storeHasil(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'diagnostic_order_id' => 'required|uuid|exists:diagnostic_orders,id',
            'expertise_data'      => 'required|array',
            'attachment_path'     => 'nullable|string|max:500',
            'notes'               => 'nullable|string|max:2000',
        ]);

        // Validate expertise_data structure per test_type
        $order = \App\Models\DiagnosticOrder::findOrFail($validated['diagnostic_order_id']);
        $this->validateExpertiseData($request, $order->test_type);

        try {
            $result = $this->service->storeResult($validated['diagnostic_order_id'], $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Hasil penunjang disimpan', 201);
    }

    /** PUT /penunjang/hasil/{id} */
    public function updateHasil(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'expertise_data'  => 'nullable|array',
            'attachment_path' => 'nullable|string|max:500',
            'notes'           => 'nullable|string|max:2000',
        ]);

        try {
            $result = $this->service->updateResult($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Hasil diperbarui');
    }

    /**
     * POST /penunjang/hasil/upload-attachment
     * Upload file lampiran (gambar/PDF print-out alat). Return path relatif disk public
     * untuk disimpan di kolom attachment_path saat storeHasil/updateHasil.
     */
    public function uploadHasilAttachment(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimetypes:image/jpeg,image/png,image/webp,application/pdf|max:10240',
        ]);

        $path = $request->file('file')->store('penunjang-hasil', 'public');
        $url  = Storage::disk('public')->url($path);

        return $this->ok(['path' => $path, 'url' => $url], 'File diunggah');
    }

    /**
     * POST /penunjang/hasil/{id}/selesai
     * Kunci hasil → notify dokter → re-queue ke DOKTER jika semua order selesai.
     * Jika Biometri → auto-generate IOL recommendation.
     */
    public function selesaiHasil(string $id): JsonResponse
    {
        try {
            $result = $this->service->finalizeResult($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Hasil dikunci. Dokter sudah dinotifikasi.');
    }

    // =========================================================================
    // IOL RECOMMENDATION
    // =========================================================================

    /** GET /penunjang/iol-rekomendasi/{visitId} */
    public function showIolRekomendasi(string $visitId): JsonResponse
    {
        return $this->ok($this->service->getIolRekomendasi($visitId));
    }

    /**
     * POST /penunjang/iol-rekomendasi
     * Manual input — atau auto-generated dari finalizeResult Biometri.
     */
    public function storeIolRekomendasi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id'             => 'required|uuid|exists:visits,id',
            'diagnostic_result_id' => 'nullable|uuid|exists:diagnostic_results,id',
            'eye_side'             => 'required|in:OD,OS',
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

    /** PUT /penunjang/iol-rekomendasi/{id} */
    public function updateIolRekomendasi(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'eye_side'          => 'sometimes|in:OD,OS',
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
    // PRIVATE — expertise_data validation per test type
    // =========================================================================

    private function validateExpertiseData(Request $request, string $testType): void
    {
        $rules = match ($testType) {
            'OCT' => [
                'expertise_data.findings' => 'nullable|string',
                'expertise_data.measurements' => 'nullable|array',
                'expertise_data.measurements.retinal_thickness_od' => 'nullable|numeric',
                'expertise_data.measurements.retinal_thickness_os' => 'nullable|numeric',
            ],
            'USG' => [
                'expertise_data.axial_length_od' => 'nullable|numeric|between:10,35',
                'expertise_data.axial_length_os' => 'nullable|numeric|between:10,35',
                'expertise_data.findings' => 'nullable|string',
            ],
            // Biometri kini fokus rekomendasi IOL saja (power/tipe/brand per mata) —
            // field AL/K1/K2/ACD dihapus (lihat IolRecommendation → request gudang Bedah).
            DiagnosticTestType::BIOMETRI_CODE => [
                'expertise_data.od' => 'nullable|array',
                'expertise_data.od.recommended_iol_power' => 'nullable|numeric|between:0,40',
                'expertise_data.od.iol_type' => 'nullable|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
                'expertise_data.od.brand'    => 'nullable|string|max:100',
                'expertise_data.os' => 'nullable|array',
                'expertise_data.os.recommended_iol_power' => 'nullable|numeric|between:0,40',
                'expertise_data.os.iol_type' => 'nullable|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
                'expertise_data.os.brand'    => 'nullable|string|max:100',
            ],
            'Topografi' => [
                'expertise_data.findings'        => 'nullable|string',
                'expertise_data.irregularity_od' => 'nullable|string',
                'expertise_data.irregularity_os' => 'nullable|string',
            ],
            default => [],
        };

        if (! empty($rules)) {
            $request->validate($rules);
        }
    }

    // =========================================================================
    // INBOX HASIL TAK-TERTAUT (ingest gagal cocok otomatis → tautkan manual)
    // =========================================================================

    /** GET /penunjang/inbox?source=OCT|USG_WATCHER&tanggal=Y-m-d (default hari ini) */
    public function indexInbox(Request $request): JsonResponse
    {
        return $this->ok($this->service->getInbox($request->query('source'), $request->query('tanggal')));
    }

    /** GET /penunjang/inbox/assignable?search=&date= — kandidat order penautan */
    public function assignableOrders(Request $request): JsonResponse
    {
        return $this->ok($this->service->searchAssignableOrders($request->only(['search', 'date'])));
    }

    /** POST /penunjang/inbox/{id}/assign  body: { order_id } */
    public function assignInbox(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|uuid|exists:diagnostic_orders,id',
        ]);

        try {
            $inbox = $this->service->assignInbox($id, $validated['order_id']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($inbox, 'Hasil ditautkan ke order');
    }

    /** POST /penunjang/inbox/{id}/discard */
    public function discardInbox(string $id): JsonResponse
    {
        try {
            $this->service->discardInbox($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Item inbox dibuang');
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
