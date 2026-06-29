<?php

namespace App\Http\Controllers;

use App\Services\FarmasiService;
use App\Support\SpreadsheetHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FarmasiController extends Controller
{
    public function __construct(private readonly FarmasiService $service) {}

    // =========================================================================
    // ANTRIAN
    // =========================================================================

    /** GET /farmasi/antrian */
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

    public function lewatiAntrian(string $id): JsonResponse
    {
        try {
            $queue = $this->service->lewatiAntrian($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($queue, 'Pasien dipindah ke akhir antrean');
    }

    /**
     * GET /farmasi/harga-obat?medication_id=&visit_id=
     * Preview harga obat tambahan sesuai penjamin pasien (harga yang ditagih kasir).
     */
    public function previewHargaObat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'medication_id' => ['required', 'string'],
            'visit_id'      => ['nullable', 'string'],
        ]);

        try {
            $preview = $this->service->previewHargaObat($data['medication_id'], $data['visit_id'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($preview);
    }

    // =========================================================================
    // RESEP OBAT
    // =========================================================================

    /**
     * GET /farmasi/resep
     * Query: tanggal, status (DRAFT|SUBMITTED|DISPENSING|DISPENSED|CANCELLED), search, per_page
     */
    public function indexResep(Request $request): JsonResponse
    {
        return $this->ok($this->service->getPrescriptions(
            $request->only(['tanggal', 'status', 'search', 'per_page'])
        ));
    }

    /** GET /farmasi/resep/{id} */
    public function showResep(string $id): JsonResponse
    {
        return $this->ok($this->service->getPrescriptionById($id));
    }

    /** PUT /farmasi/resep/{id}/dispensing — mulai proses */
    public function startDispensing(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->startDispensing($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Resep mulai diproses');
    }

    /** PUT /farmasi/resep/{id}/selesai — selesai dispensing + potong stok */
    public function selesaiDispensing(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->selesaiDispensing($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Resep diselesaikan. Stok obat dikurangi.');
    }

    /** PUT /farmasi/resep/{id}/cancel */
    public function cancelResep(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->cancelResep($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Resep dibatalkan');
    }

    // -------------------------------------------------------------------------
    // Verifikasi Farmasi (gate sebelum tagihan Kasir) — alur D→K→F

    /** GET /farmasi/verifikasi — worklist resep + BHP dokter yang perlu/siap diverifikasi */
    public function indexVerifikasi(Request $request): JsonResponse
    {
        $filters = $request->only(['tanggal', 'search']);
        $prescriptions = $this->service->getVerificationQueue($filters);
        // BHP-only: pasien dgn BHP belum-verif TANPA resep di worklist (mis. injeksi).
        $bhpOnly = $this->service->getBhpOnlyVerificationVisits(
            $prescriptions->pluck('visit_id')->filter()->unique()->values()->all(),
            $filters
        );

        return $this->ok(['prescriptions' => $prescriptions, 'bhp_only' => $bhpOnly]);
    }

    /** PUT /farmasi/resep/{id}/verifikasi — kunci resep (Kasir baru bisa menagih) */
    public function verifikasiResep(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->verifyPrescription($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Resep diverifikasi & dikunci. Kasir dapat membuat tagihan.');
    }

    /** PUT /farmasi/resep/{id}/buka-verifikasi — buka kunci (koreksi sebelum bayar) */
    public function bukaVerifikasiResep(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->unverifyPrescription($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Kunci verifikasi dibuka');
    }

    /** PUT /farmasi/visit/{visitId}/bhp/verifikasi — kunci SEMUA BHP dokter pada kunjungan */
    public function verifikasiBhp(string $visitId): JsonResponse
    {
        try {
            $bhp = $this->service->verifyVisitBhp($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($bhp, 'BHP diverifikasi & dikunci. Kasir dapat membuat tagihan.');
    }

    /** PUT /farmasi/visit/{visitId}/bhp/buka-verifikasi — buka kunci BHP (koreksi sebelum bayar) */
    public function bukaVerifikasiBhp(string $visitId): JsonResponse
    {
        try {
            $bhp = $this->service->unverifyVisitBhp($visitId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($bhp, 'Kunci verifikasi BHP dibuka');
    }

    /** PUT /farmasi/bhp-usage/{id} — Farmasi ubah qty BHP saat verifikasi */
    public function updateBhpUsage(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['quantity' => 'required|integer|min:1']);
        try {
            $usage = $this->service->updateBhpUsageQty($id, (int) $validated['quantity']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($usage, 'Jumlah BHP diperbarui');
    }

    /** DELETE /farmasi/bhp-usage/{id} — Farmasi hapus BHP saat verifikasi (wajib alasan) */
    public function deleteBhpUsage(Request $request, string $id): JsonResponse
    {
        try {
            $this->service->removeBhpUsage($id, $request->input('change_reason'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'BHP dihapus');
    }

    // -------------------------------------------------------------------------
    // Item dispensing

    /**
     * POST /farmasi/resep/{resepId}/item
     * Body: { items: [{medication_id, quantity, dosage, instructions, notes, source?}] }
     * source: RESEP (default) | TAMBAHAN (obat tambahan apotek, golongan BEBAS/BEBAS_TERBATAS).
     */
    public function storeItemDispensing(Request $request, string $resepId): JsonResponse
    {
        $validated = $request->validate([
            'items'                    => 'required|array|min:1',
            'items.*.medication_id'    => 'required|uuid|exists:medications,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.dosage'           => 'nullable|string|max:100',
            'items.*.instructions'     => 'nullable|string|max:255',
            'items.*.notes'            => 'nullable|string|max:255',
            'items.*.source'           => 'nullable|in:RESEP,TAMBAHAN',
            'items.*.change_reason'    => 'nullable|string|max:120',
        ]);

        try {
            $items = $this->service->storeItemDispensing($resepId, $validated['items']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($items, 'Item resep ditambahkan', 201);
    }

    /**
     * POST /farmasi/kunjungan/{visitId}/resep-otc
     * Penjualan obat tambahan (OTC) untuk pasien antrean Farmasi tanpa resep dokter.
     * Body: { items: [{medication_id, quantity, dosage, instructions, notes}] }
     */
    public function storeOtcPrescription(Request $request, string $visitId): JsonResponse
    {
        $validated = $request->validate([
            'items'                    => 'required|array|min:1',
            'items.*.medication_id'    => 'required|uuid|exists:medications,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.dosage'           => 'nullable|string|max:100',
            'items.*.instructions'     => 'nullable|string|max:255',
            'items.*.notes'            => 'nullable|string|max:255',
        ]);

        try {
            $prescription = $this->service->createOtcPrescription($visitId, $validated['items']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Penjualan obat tambahan dibuat', 201);
    }

    /** PUT /farmasi/resep-item/{id} */
    public function updateItemDispensing(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'quantity'      => 'sometimes|integer|min:1',
            'dosage'        => 'nullable|string|max:100',
            'instructions'  => 'nullable|string|max:255',
            'notes'         => 'nullable|string|max:255',
            // Substitusi obat (ganti dgn obat lain) + alasan terstruktur (audit BPJS).
            'medication_id' => 'sometimes|uuid|exists:medications,id',
            'change_reason' => 'nullable|string|max:120',
        ]);

        try {
            $item = $this->service->updateItemDispensing($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($item, 'Item diperbarui');
    }

    /**
     * PUT /farmasi/resep-item/{id}/kemasan — pilih varian kemasan jual (Strip/Box)
     * saat VERIFIKASI. sale_unit_id null = lepas kemasan (kembali satuan kecil).
     * split_remainder: sisa qty dipecah jadi item saudara satuan kecil (PECAH_KEMASAN).
     */
    public function setKemasanItem(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'sale_unit_id'    => 'nullable|uuid|exists:medication_sale_units,id',
            'sale_unit_qty'   => 'required_with:sale_unit_id|integer|min:1',
            'split_remainder' => 'nullable|boolean',
            'change_reason'   => 'nullable|string|max:120',
        ]);

        try {
            $item = $this->service->setKemasanItem($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($item, 'Kemasan item diperbarui');
    }

    /** DELETE /farmasi/resep-item/{id} — body/query opsional: change_reason (audit) */
    public function deleteItemDispensing(Request $request, string $id): JsonResponse
    {
        try {
            $this->service->deleteItemDispensing($id, $request->input('change_reason'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok(null, 'Item dihapus');
    }

    // =========================================================================
    // SURGERY REQUEST (BHP + IOL dari Bedah)
    // =========================================================================

    /**
     * GET /farmasi/surgery-request
     * Query: status (default: REQUESTED), tanggal
     */
    public function indexSurgeryRequest(Request $request): JsonResponse
    {
        return $this->ok($this->service->getSurgeryRequests(
            $request->only(['status', 'tanggal'])
        ));
    }

    /** GET /farmasi/surgery-request/{id} */
    public function showSurgeryRequest(string $id): JsonResponse
    {
        return $this->ok($this->service->getSurgeryRequestById($id));
    }

    /**
     * PUT /farmasi/surgery-request/{id}/siapkan
     * Tandai Farmasi sedang menyiapkan item (audit trail only, no status change).
     */
    public function siapkanSurgeryRequest(string $id): JsonResponse
    {
        try {
            $surgeryRequest = $this->service->siapkanSurgeryRequest($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($surgeryRequest, 'Penyiapan BHP+IOL dimulai');
    }

    /**
     * POST /farmasi/surgery-request/{id}/assign-iol
     * Assign IOL item spesifik ke satu IOL line di request.
     * Body: { request_iol_id, iol_item_id }
     * Validasi power ±0.5 D, is_used = false.
     */
    public function assignIol(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'request_iol_id' => 'required|uuid|exists:surgery_request_iol,id',
            'iol_item_id'    => 'required|uuid|exists:iol_items,id',
        ]);

        try {
            $requestIol = $this->service->assignIolToRequest(
                $validated['request_iol_id'],
                $validated['iol_item_id']
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($requestIol, 'IOL berhasil di-assign');
    }

    /**
     * POST /farmasi/surgery-request/{id}/kirim
     * Kirim ke Bedah (REQUESTED → SENT).
     * Guard: semua IOL harus sudah di-assign.
     * Side-effect: deduct BHP stock.
     */
    public function kirimSurgeryRequest(string $id): JsonResponse
    {
        try {
            $surgeryRequest = $this->service->kirimSurgeryRequest($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($surgeryRequest, 'BHP+IOL dikirim ke Bedah. Stok BHP dikurangi.');
    }

    // =========================================================================
    // MASTER STOK — OBAT
    // =========================================================================

    /**
     * GET /farmasi/stok/obat
     * Query: search, formularium, alert (boolean), per_page
     */
    public function indexStokObat(Request $request): JsonResponse
    {
        return $this->ok($this->service->getStokObat(
            $request->only(['search', 'formularium', 'alert', 'per_page'])
        ));
    }

    public function showStokObat(string $id): JsonResponse
    {
        return $this->ok(\App\Models\Medication::findOrFail($id));
    }

    /**
     * PUT /farmasi/stok/obat/{id}
     * Body: { stock, min_stock, price, expiry_date, batch_number }
     */
    public function updateStokObat(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'stock'        => 'nullable|integer|min:0',
            'min_stock'    => 'nullable|integer|min:0',
            'price'        => 'nullable|numeric|min:0',
            'expiry_date'  => 'nullable|date',
            'batch_number' => 'nullable|string|max:100',
        ]);

        try {
            $medication = $this->service->updateStokObat($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($medication, 'Stok obat diperbarui');
    }

    // =========================================================================
    // MASTER STOK — BHP
    // =========================================================================

    /**
     * GET /farmasi/stok/bhp
     * Query: search, alert (boolean), per_page
     */
    public function indexStokBhp(Request $request): JsonResponse
    {
        return $this->ok($this->service->getStokBhp(
            $request->only(['search', 'alert', 'per_page'])
        ));
    }

    /**
     * PUT /farmasi/stok/bhp/{id}
     * Body: { stock, min_stock, price }
     */
    public function updateStokBhp(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'stock'     => 'nullable|integer|min:0',
            'min_stock' => 'nullable|integer|min:0',
            'price'     => 'nullable|numeric|min:0',
        ]);

        try {
            $bhp = $this->service->updateStokBhp($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($bhp, 'Stok BHP diperbarui');
    }

    // =========================================================================
    // MASTER STOK — IOL
    // =========================================================================

    /**
     * GET /farmasi/stok/iol
     * Query: available_only (boolean), iol_type, brand, power, per_page
     */
    public function indexStokIol(Request $request): JsonResponse
    {
        return $this->ok($this->service->getStokIol(
            $request->only(['available_only', 'iol_type', 'brand', 'power', 'per_page'])
        ));
    }

    /**
     * PUT /farmasi/stok/iol/{id}
     * Body: { brand, model, iol_type, material, power, lot_number, serial_number, gs1_barcode, price, is_active }
     */
    public function updateStokIol(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'brand'         => 'nullable|string|max:100',
            'model'         => 'nullable|string|max:100',
            'iol_type'      => 'nullable|in:MONOFOCAL,MULTIFOCAL,TORIC,TRIFOCAL,EDOF,PHAKIC',
            'material'      => 'nullable|in:Acrylic,Silicone,PMMA',
            'power'         => 'nullable|numeric|between:0,40',
            'lot_number'    => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'gs1_barcode'   => 'nullable|string|max:255',
            'price'         => 'nullable|numeric|min:0',
            'is_active'     => 'nullable|boolean',
        ]);

        try {
            $iol = $this->service->updateStokIol($id, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($iol, 'Data IOL diperbarui');
    }

    /** GET /farmasi/stok/alert — semua item di bawah min_stock */
    public function stokAlert(): JsonResponse
    {
        return $this->ok($this->service->getStokAlert());
    }

    /**
     * GET /farmasi/stok/opname/export?format=xlsx|csv (default xlsx)
     * Lembar kerja stok opname unit Farmasi: kolom "Stok Fisik" & "Selisih"
     * sengaja dikosongkan untuk diisi manual saat hitung fisik di rak.
     */
    public function exportOpname(Request $request): Response
    {
        // Opname dipisah per jenis: stok OBAT (default) atau BHP (bahan habis pakai).
        $kind = strtolower((string) $request->query('kind', 'obat')) === 'bhp' ? 'bhp' : 'obat';
        $rows = $kind === 'bhp'
            ? $this->service->getStokBhp(['per_page' => 'all'])
            : $this->service->getStokObat(['per_page' => 'all']);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['No', 'Nama Produk', 'Formularium', 'Unit', 'Stok Sistem', 'Stok Fisik', 'Selisih'], ',', '"', '\\');
        $i = 1;
        foreach ($rows as $m) {
            fputcsv($out, [
                $i++,
                $m->name,
                $m->formularium ?? '',
                $m->unit ?? '',
                (int) ($m->stock ?? 0),
                '', // Stok Fisik — diisi manual
                '', // Selisih — diisi manual
            ], ',', '"', '\\');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $baseName = 'stok-opname-' . $kind . '-' . now()->format('Ymd');
        if (strtolower((string) $request->query('format', 'xlsx')) !== 'csv') {
            $xlsx = SpreadsheetHelper::csvToXlsx($csv, 'Stok Opname');
            return response($xlsx, 200, [
                'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$baseName}.xlsx\"",
            ]);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$baseName}.csv\"",
        ]);
    }

    // =========================================================================
    // DISPENSING RAWAT INAP (permintaan obat pasien dirawat — type RANAP)
    // =========================================================================

    /** GET /farmasi/ranap/permintaan */
    public function indexRanapRequest(): JsonResponse
    {
        return $this->ok($this->service->getRanapRequests());
    }

    /** PUT /farmasi/ranap/permintaan/{id}/siapkan */
    public function siapkanRanapRequest(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->startRanapDispensing($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Permintaan obat mulai disiapkan');
    }

    /** PUT /farmasi/ranap/permintaan/{id}/serah — potong stok + tagih inpatient_charges */
    public function serahRanapRequest(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->serahRanapRequest($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Obat diserahkan ke ruangan. Stok dikurangi & biaya tercatat.');
    }

    /** PUT /farmasi/ranap/permintaan/{id}/tolak */
    public function tolakRanapRequest(string $id): JsonResponse
    {
        try {
            $prescription = $this->service->tolakRanapRequest($id);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }

        return $this->ok($prescription, 'Permintaan obat dibatalkan');
    }

    // =========================================================================
    // RIWAYAT PEMBERIAN OBAT (laporan "diberikan ke siapa")
    // =========================================================================

    /** GET /farmasi/obat/{medicationId}/riwayat-pemberian?limit= */
    public function riwayatPemberianObat(Request $request, string $medicationId): JsonResponse
    {
        return $this->ok($this->service->getMedicationDispenseHistory(
            $medicationId,
            $request->only(['limit'])
        ));
    }

    /** GET /farmasi/riwayat-pemberian?search=&date_from=&date_to=&jenis=&per_page=&page= */
    public function indexRiwayatPemberian(Request $request): JsonResponse
    {
        return $this->ok($this->service->getDispenseHistory(
            $request->only(['search', 'date_from', 'date_to', 'jenis', 'per_page', 'page'])
        ));
    }

    /**
     * GET /farmasi/riwayat-pemberian/export?format=xlsx|csv — unduh riwayat (sesuai filter).
     * Default XLSX (dibatasi agar aman RAM); format=csv ditulis streaming utk data besar.
     */
    public function exportRiwayatPemberian(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $filters = $request->only(['search', 'date_from', 'date_to', 'jenis']);
        $asCsv   = strtolower((string) $request->query('format', 'xlsx')) === 'csv';
        $limit   = $asCsv ? FarmasiService::DISPENSE_EXPORT_CSV_MAX : FarmasiService::DISPENSE_EXPORT_XLSX_MAX;
        $base    = 'riwayat-pemberian-obat-' . now()->format('Ymd');
        $header  = ['No', 'Tanggal', 'Pasien', 'No. RM', 'Obat', 'Jumlah', 'Jenis', 'Petugas'];

        $mapRow = function (object $r, int $i): array {
            $tgl = $r->tanggal ? \Illuminate\Support\Carbon::parse($r->tanggal)->format('d-m-Y H:i') : '';

            return [$i, $tgl, $r->pasien ?? '', $r->no_rm ?? '', $r->obat ?? '',
                (float) ($r->quantity ?? 0), $r->sumber ?? '', $r->petugas ?? ''];
        };

        // CSV: tulis langsung ke output stream (memory-flat) untuk dataset besar.
        if ($asCsv) {
            $rows = $this->service->exportDispenseHistory($filters, $limit);

            return response()->streamDownload(function () use ($rows, $header, $mapRow) {
                $out = fopen('php://output', 'w');
                fputcsv($out, $header, ',', '"', '\\');
                $i = 1;
                foreach ($rows as $r) {
                    fputcsv($out, $mapRow($r, $i++), ',', '"', '\\');
                }
                fclose($out);
            }, "{$base}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        // XLSX: rangkai CSV (cursor → memory-flat) lalu konversi via PhpSpreadsheet.
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header, ',', '"', '\\');
        $i = 1;
        foreach ($this->service->exportDispenseHistory($filters, $limit) as $r) {
            fputcsv($out, $mapRow($r, $i++), ',', '"', '\\');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $xlsx = SpreadsheetHelper::csvToXlsx($csv, 'Riwayat Pemberian');

        return response($xlsx, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$base}.xlsx\"",
        ]);
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
