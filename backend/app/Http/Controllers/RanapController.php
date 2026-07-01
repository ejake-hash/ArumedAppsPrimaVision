<?php

namespace App\Http\Controllers;

use App\Models\Visit;
use App\Services\RanapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RanapController extends Controller
{
    public function __construct(private readonly RanapService $service) {}

    /** GET /rawat-inap/bed-board */
    public function bedBoard(): JsonResponse
    {
        return $this->ok($this->service->bedBoard());
    }

    /** GET /rawat-inap/menunggu-kamar */
    public function waitingForBed(): JsonResponse
    {
        return $this->ok($this->service->waitingForBed());
    }

    /** GET /rawat-inap/aktif */
    public function activeInpatients(): JsonResponse
    {
        return $this->ok($this->service->activeInpatients());
    }

    /** GET /rawat-inap/{visitId} */
    public function detail(string $visitId): JsonResponse
    {
        try {
            return $this->ok($this->service->detail($visitId));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /rawat-inap/{visitId}/admit */
    public function admit(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'bed_id'           => 'required|uuid',
            'kelas_hak'        => 'required|string|max:5',
            'dpjp_id'          => 'nullable|uuid',
            'admission_at'     => 'nullable|date',
            'spri_tgl_rencana' => 'nullable|date',
        ]);

        try {
            $visit = Visit::findOrFail($visitId);
            $result = $this->service->admit(
                $visit, $data['bed_id'], $data['kelas_hak'],
                $data['dpjp_id'] ?? null, $data['admission_at'] ?? null,
                $data['spri_tgl_rencana'] ?? null
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Pasien dirawat inap — bed ditempati');
    }

    /** POST /rawat-inap/{visitId}/transfer */
    public function transfer(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'bed_id' => 'required|uuid',
            'reason' => 'required|in:TRANSFER,TITIP_KELAS,UPGRADE_KELAS,DOWNGRADE_KELAS',
        ]);

        try {
            $visit  = Visit::findOrFail($visitId);
            $result = $this->service->transferBed($visit, $data['bed_id'], $data['reason']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Pasien dipindahkan');
    }

    /** POST /rawat-inap/bed/{bedId}/available — tandai bed selesai dibersihkan. */
    public function markBedAvailable(string $bedId): JsonResponse
    {
        try {
            $result = $this->service->markBedAvailable($bedId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Bed siap dipakai');
    }

    /** POST /rawat-inap/{visitId}/charge */
    public function addCharge(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'charge_type'    => 'required|string|max:20',
            'description'    => 'required|string|max:255',
            'quantity'       => 'nullable|numeric|min:0',
            'unit_price'     => 'nullable|numeric|min:0',
            'charge_date'    => 'nullable|date',
            'reference_type' => 'nullable|string|max:100',
            'reference_id'   => 'nullable|uuid',
        ]);

        try {
            $visit  = Visit::findOrFail($visitId);
            $charge = $this->service->addCharge($visit, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($charge, 'Biaya dicatat');
    }

    /** GET /rawat-inap/{visitId}/tarif-tindakan — picker tindakan + harga. */
    public function tarifTindakan(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->tarifTindakan($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** GET /rawat-inap/{visitId}/daftar-obat — picker obat + harga. */
    public function daftarObat(string $visitId, Request $request): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->daftarObat($visit, $request->query('search')));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /rawat-inap/{visitId}/tindakan — harga resolve otomatis. */
    public function addTindakan(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'procedure_id' => 'required|uuid',
            'quantity'     => 'nullable|numeric|min:0.01',
        ]);

        try {
            $visit  = Visit::findOrFail($visitId);
            $charge = $this->service->addTindakan($visit, $data['procedure_id'], $data['quantity'] ?? 1);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($charge, 'Tindakan dicatat');
    }

    /** POST /rawat-inap/{visitId}/obat — harga resolve otomatis. */
    public function addObat(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'medication_id' => 'required|uuid',
            'quantity'      => 'nullable|numeric|min:0.01',
        ]);

        try {
            $visit  = Visit::findOrFail($visitId);
            $charge = $this->service->addObat($visit, $data['medication_id'], $data['quantity'] ?? 1);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($charge, 'Obat dicatat');
    }

    /** DELETE /rawat-inap/{visitId}/charge/{chargeId} */
    public function deleteCharge(string $visitId, string $chargeId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            $this->service->deleteCharge($visit, $chargeId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Biaya dihapus');
    }

    /** GET /rawat-inap/{visitId}/permintaan-obat — daftar permintaan obat ke Farmasi. */
    public function listPermintaanObat(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->listMedicationRequests($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /**
     * POST /rawat-inap/{visitId}/permintaan-obat — minta obat ke Farmasi (dispensing
     * ke ruangan). Tagihan + potong stok terbit saat Farmasi serah, bukan di sini.
     */
    public function createPermintaanObat(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'items'                 => 'required|array|min:1',
            'items.*.medication_id' => 'required|uuid|exists:medications,id',
            'items.*.quantity'      => 'required|integer|min:1',
            'items.*.dose'          => 'nullable|string|max:100',
            'items.*.frequency'     => 'nullable|string|max:100',
            'items.*.route'         => 'nullable|string|max:100',
            'items.*.instructions'  => 'nullable|string|max:255',
            'pharmacy_note'         => 'nullable|string|max:500',
            // Cara serah: DELIVER=antar ke kamar (default) / PICKUP=ambil di farmasi.
            'fulfillment_mode'      => 'nullable|in:DELIVER,PICKUP',
        ]);

        try {
            $visit        = Visit::findOrFail($visitId);
            $prescription = $this->service->createMedicationRequest($visit, $data['items'], $data['pharmacy_note'] ?? null, $data['fulfillment_mode'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Permintaan obat dikirim ke Farmasi', 201);
    }

    /** DELETE /rawat-inap/{visitId}/permintaan-obat/{id} — batalkan sebelum diserahkan Farmasi. */
    public function cancelPermintaanObat(string $visitId, string $prescriptionId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            $rx    = $this->service->cancelMedicationRequest($visit, $prescriptionId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($rx, 'Permintaan obat dibatalkan');
    }

    // =========================================================================
    // PERMINTAAN BHP KE FARMASI (visit_bhp_usages) — masuk kwitansi setelah verif
    // =========================================================================

    /** GET /rawat-inap/{visitId}/tarif-bhp — picker BHP + stok Farmasi + harga. */
    public function tarifBhp(string $visitId, Request $request): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->tarifBhp($visit, $request->query('search')));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** GET /rawat-inap/{visitId}/bhp — daftar BHP yang diminta + status verifikasi. */
    public function listBhp(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->listBhpUsages($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /rawat-inap/{visitId}/bhp — minta BHP ke Farmasi (tagih setelah verif). */
    public function addBhp(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'bhp_item_id' => 'required|uuid|exists:bhp_items,id',
            'quantity'    => 'nullable|integer|min:1',
            'notes'       => 'nullable|string|max:255',
        ]);

        try {
            $visit = Visit::findOrFail($visitId);
            $usage = $this->service->addBhp($visit, $data['bhp_item_id'], $data['quantity'] ?? 1, $data['notes'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($usage, 'BHP diminta ke Farmasi', 201);
    }

    /** DELETE /rawat-inap/{visitId}/bhp/{id} — hapus permintaan BHP (sebelum serah). */
    public function deleteBhp(string $visitId, string $id): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            $this->service->deleteBhp($visit, $id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Permintaan BHP dihapus');
    }

    // =========================================================================
    // ORDER PENUNJANG (lab/radiologi) — mirror Dokter; tagihan via tindakan terpisah
    // =========================================================================

    /** GET /rawat-inap/{visitId}/order-penunjang — daftar order + hasil. */
    public function listOrderPenunjang(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->listOrderPenunjang($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /rawat-inap/{visitId}/order-penunjang — buat order + antrean penunjang. */
    public function storeOrderPenunjang(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'test_type' => 'required|string|max:100',
            'eye_side'  => 'nullable|string|max:20',
            'notes'     => 'nullable|string|max:255',
        ]);

        try {
            $visit = Visit::findOrFail($visitId);
            $order = $this->service->storeOrderPenunjang($visit, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($order, 'Order penunjang dibuat', 201);
    }

    /** DELETE /rawat-inap/{visitId}/order-penunjang/{id} — batalkan order (pre-proses). */
    public function cancelOrderPenunjang(string $visitId, string $id): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            $this->service->cancelOrderPenunjang($visit, $id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Order penunjang dibatalkan');
    }

    // =========================================================================
    // eMAR — pemberian obat ke pasien (PKPO 4.3)
    // =========================================================================

    /** GET /rawat-inap/{visitId}/mar — order obat aktif + riwayat pemberian. */
    public function marBoard(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->marBoard($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /rawat-inap/{visitId}/mar — catat pemberian obat (jam + perawat otomatis). */
    public function recordAdministration(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'prescription_item_id' => 'nullable|uuid|exists:prescription_items,id',
            'medication_id'        => 'nullable|uuid|exists:medications,id',
            'medication_name'      => 'nullable|string|max:255',
            'dose'                 => 'nullable|string|max:100',
            'route'                => 'nullable|string|max:100',
            'administered_at'      => 'nullable|date',
            'status'               => 'nullable|in:GIVEN,HELD,SKIPPED',
            'reason'               => 'nullable|string|max:255',
            'notes'                => 'nullable|string|max:500',
        ]);

        try {
            $visit = Visit::findOrFail($visitId);
            $rec   = $this->service->recordAdministration($visit, $data);
            return $this->ok($rec, 'Pemberian obat dicatat', 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    /** DELETE /rawat-inap/{visitId}/mar/{id} — hapus catatan pemberian (koreksi). */
    public function deleteAdministration(string $visitId, string $id): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            $this->service->deleteAdministration($visit, $id);
            return $this->ok(null, 'Catatan pemberian dihapus');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    // =========================================================================
    // BALANCE CAIRAN (intake/output) — STARKES PAP
    // =========================================================================

    /** GET /rawat-inap/{visitId}/fluid-balance — catatan + ringkasan saldo. */
    public function fluidBalance(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->fluidBalanceBoard($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /rawat-inap/{visitId}/fluid-balance — tambah intake/output. */
    public function addFluidBalance(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction'   => 'required|in:INTAKE,OUTPUT',
            'category'    => 'nullable|string|max:100',
            'volume_ml'   => 'required|integer|min:1|max:100000',
            'recorded_at' => 'nullable|date',
            'notes'       => 'nullable|string|max:255',
        ]);

        try {
            $visit = Visit::findOrFail($visitId);
            $rec   = $this->service->addFluidBalance($visit, $data);
            return $this->ok($rec, 'Catatan cairan ditambahkan', 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    /** DELETE /rawat-inap/{visitId}/fluid-balance/{id} — hapus catatan. */
    public function deleteFluidBalance(string $visitId, string $id): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            $this->service->deleteFluidBalance($visit, $id);
            return $this->ok(null, 'Catatan cairan dihapus');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    // =========================================================================
    // DOKUMEN/HASIL EKSTERNAL (Fase 8C)
    // =========================================================================

    /** GET /rawat-inap/{visitId}/dokumen — daftar hasil eksternal. */
    public function indexDocuments(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->documents($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /rawat-inap/{visitId}/dokumen — upload hasil eksternal (PDF/gambar). */
    public function uploadDocument(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => 'nullable|in:LAB,RADIOLOGI,EKG,LAINNYA',
            'title'    => 'nullable|string|max:200',
            'file'     => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', // max 10MB
        ]);

        try {
            $visit = Visit::findOrFail($visitId);
            $doc   = $this->service->uploadDocument($visit, $data, $request->file('file'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($doc, 'Hasil eksternal diunggah', 201);
    }

    /** DELETE /rawat-inap/{visitId}/dokumen/{documentId} */
    public function deleteDocument(string $visitId, string $documentId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            $this->service->deleteDocument($visit, $documentId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Dokumen dihapus');
    }

    /** GET /rawat-inap/{visitId}/cppt — daftar CPPT pasien inap. */
    public function indexCppt(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->cpptEntries($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 404);
        }
    }

    /** POST /rawat-inap/{visitId}/cppt — tambah CPPT terintegrasi (SOAP + TTV). */
    public function addCppt(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'notes'      => 'nullable|string',
            'soap_s'     => 'nullable|string',
            'soap_o'     => 'nullable|string',
            'soap_a'     => 'nullable|string',
            'soap_p'     => 'nullable|string',
            'instruksi'  => 'nullable|string',
            'td_sistol'  => 'nullable|integer',
            'td_diastol' => 'nullable|integer',
            'nadi'       => 'nullable|integer',
            'suhu'       => 'nullable|numeric',
            'respirasi'  => 'nullable|integer',
            'spo2'       => 'nullable|numeric',
            'kgd'        => 'nullable|numeric',
            'pain_scale' => 'nullable|integer',
            'visus_od'   => 'nullable|string|max:20',
            'visus_os'   => 'nullable|string|max:20',
            'iop_od'     => 'nullable|numeric',
            'iop_os'     => 'nullable|numeric',
            'iop_method' => 'nullable|string|max:50',
        ]);

        // Minimal salah satu isi naratif harus ada (SOAP atau catatan bebas).
        if (! array_filter([
            $data['notes'] ?? null, $data['soap_s'] ?? null, $data['soap_o'] ?? null,
            $data['soap_a'] ?? null, $data['soap_p'] ?? null, $data['instruksi'] ?? null,
        ])) {
            return $this->error('Isi CPPT (SOAP atau catatan) wajib diisi.', 422);
        }

        try {
            $visit = Visit::findOrFail($visitId);
            $entry = $this->service->addCppt($visit, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($entry, 'CPPT dicatat');
    }

    /** PUT /rawat-inap/cppt/{id} — soft-edit CPPT. */
    public function updateCppt(string $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'notes'      => 'nullable|string',
            'soap_s'     => 'nullable|string',
            'soap_o'     => 'nullable|string',
            'soap_a'     => 'nullable|string',
            'soap_p'     => 'nullable|string',
            'instruksi'  => 'nullable|string',
            'td_sistol'  => 'nullable|integer',
            'td_diastol' => 'nullable|integer',
            'nadi'       => 'nullable|integer',
            'suhu'       => 'nullable|numeric',
            'respirasi'  => 'nullable|integer',
            'spo2'       => 'nullable|numeric',
            'kgd'        => 'nullable|numeric',
            'pain_scale' => 'nullable|integer',
            'visus_od'   => 'nullable|string|max:20',
            'visus_os'   => 'nullable|string|max:20',
            'iop_od'     => 'nullable|numeric',
            'iop_os'     => 'nullable|numeric',
            'iop_method' => 'nullable|string|max:50',
        ]);

        try {
            $entry = $this->service->updateCppt($id, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($entry, 'CPPT diperbarui');
    }

    /** POST /rawat-inap/cppt/{id}/verify — verifikasi DPJP atas entri CPPT. */
    public function verifyCppt(string $id): JsonResponse
    {
        try {
            $entry = $this->service->verifyCppt($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($entry, 'CPPT diverifikasi DPJP');
    }

    /** POST /rawat-inap/{visitId}/kirim-bedah */
    public function sendToBedah(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'surgery_schedule_id' => 'nullable|uuid',
            // Opsional: bila diisi (paket wajib), jadwal operasi dibuat otomatis
            // dan pasien tampil di papan "Bedah Terjadwal".
            'surgery_package_id'  => 'nullable|uuid',
            'scheduled_date'      => 'nullable|date',
            'scheduled_time'      => 'nullable',
            'operation_room'      => 'nullable|string|max:100',
            'notes'               => 'nullable|string',
        ]);

        try {
            $visit   = Visit::findOrFail($visitId);
            $options = array_filter(
                $request->only(['surgery_package_id', 'scheduled_date', 'scheduled_time', 'operation_room', 'notes']),
                fn ($v) => $v !== null && $v !== ''
            );
            $queue = $this->service->sendToBedah($visit, $data['surgery_schedule_id'] ?? null, $options);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dikirim ke bedah (bed ditahan)');
    }

    /** POST /rawat-inap/{visitId}/discharge */
    public function discharge(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'discharge_type'              => 'required|in:PULANG_SEHAT,RUJUK,APS,MENINGGAL',
            'summary'                     => 'nullable|string',
            'follow_up_date'              => 'nullable|date',
            'follow_up_reason'            => 'nullable|string',
            // Obat pulang (opsional) → tagih (inpatient_charges) + resep ke Farmasi.
            'obat_pulang'                 => 'nullable|array',
            'obat_pulang.*.medication_id' => 'required|uuid',
            'obat_pulang.*.quantity'      => 'nullable|numeric|min:0.01',
            'obat_pulang.*.dose'          => 'nullable|string|max:100',
            'obat_pulang.*.frequency'     => 'nullable|string|max:100',
            'obat_pulang.*.route'         => 'nullable|string|max:100',
            'obat_pulang.*.duration_days' => 'nullable|integer|min:1',
            'obat_pulang.*.instructions'  => 'nullable|string',
            'obat_pulang.*.notes'         => 'nullable|string',
            // Cara serah obat pulang: DELIVER=antar ke kamar / PICKUP=ambil di loket.
            'obat_pulang_delivery'        => 'nullable|in:DELIVER,PICKUP',
        ]);

        try {
            $visit  = Visit::findOrFail($visitId);
            $result = $this->service->discharge(
                $visit,
                $data['discharge_type'],
                $data['summary'] ?? null,
                $data['follow_up_date'] ?? null,
                $data['follow_up_reason'] ?? null,
                $data['obat_pulang'] ?? [],
                $data['obat_pulang_delivery'] ?? null,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Pasien dipulangkan — diteruskan ke kasir');
    }

    // =========================================================================
    // BPJS — SEP (view/update) · SPRI (CRU) · History
    // =========================================================================

    /** GET /rawat-inap/{visitId}/sep */
    public function getSep(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::with('patient')->findOrFail($visitId);
            return $this->ok($this->service->getSep($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    /** PUT /rawat-inap/{visitId}/sep */
    public function updateSep(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'kls_rawat' => 'nullable|string|max:5',
            'catatan'   => 'nullable|string',
            'diag_awal' => 'nullable|string',
            'no_telp'   => 'nullable|string|max:20',
            'katarak'   => 'nullable|in:0,1',
        ]);

        try {
            $visit  = Visit::with('patient')->findOrFail($visitId);
            $result = $this->service->updateSep($visit, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'SEP diperbarui');
    }

    /** PUT /rawat-inap/{visitId}/sep/tgl-pulang — lapor/ulang tgl pulang SEP ke VClaim. */
    public function updateTglPulang(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate([
            'tgl_pulang' => 'nullable|date',
        ]);

        try {
            $visit  = Visit::with('patient')->findOrFail($visitId);
            $result = $this->service->updateTglPulang($visit, $data['tgl_pulang'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($result, 'Tanggal pulang SEP dilaporkan ke BPJS');
    }

    /** GET /rawat-inap/{visitId}/spri */
    public function listSpri(string $visitId): JsonResponse
    {
        try {
            $visit = Visit::findOrFail($visitId);
            return $this->ok($this->service->listSpri($visit));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    /** POST /rawat-inap/{visitId}/spri */
    public function createSpri(string $visitId, Request $request): JsonResponse
    {
        $data = $request->validate(['tgl_rencana' => 'required|date']);

        try {
            $visit = Visit::with('patient')->findOrFail($visitId);
            $spri  = $this->service->createSpri($visit, $data['tgl_rencana']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($spri, 'SPRI diterbitkan');
    }

    /** PUT /rawat-inap/spri/{spriId} */
    public function updateSpri(string $spriId, Request $request): JsonResponse
    {
        $data = $request->validate(['tgl_rencana' => 'required|date']);

        try {
            $spri = $this->service->updateSpri($spriId, $data['tgl_rencana']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($spri, 'SPRI diperbarui');
    }

    /** DELETE /rawat-inap/spri/{spriId} */
    public function deleteSpri(string $spriId): JsonResponse
    {
        try {
            $this->service->deleteSpri($spriId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'SPRI dihapus');
    }

    /** GET /rawat-inap/history?date_from=&date_to= */
    public function dischargedHistory(Request $request): JsonResponse
    {
        return $this->ok($this->service->dischargedHistory(
            $request->query('date_from'),
            $request->query('date_to'),
        ));
    }

    // =========================================================================
    // RESPONSE HELPERS (pola konsisten dengan controller lain)
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
