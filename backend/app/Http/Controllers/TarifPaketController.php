<?php

namespace App\Http\Controllers;

use App\Models\SurgeryPackageItem;
use App\Services\TarifPaketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TarifPaketController extends Controller
{
    private const TARIF_TYPES = ['tindakan', 'obat', 'bhp', 'iol'];

    public function __construct(private readonly TarifPaketService $service) {}

    // =========================================================================
    // TARIF PER PENJAMIN (delegasi)
    // =========================================================================

    public function indexTarif(Request $request, string $type): JsonResponse
    {
        $this->assertTarifType($type);
        $request->validate(['insurer_id' => 'required|uuid|exists:insurers,id']);
        return $this->ok($this->service->indexTarif(
            $type,
            $request->only(['insurer_id', 'per_page', 'include_unpriced', 'search'])
        ));
    }

    public function storeTarif(Request $request, string $type): JsonResponse
    {
        $this->assertTarifType($type);
        $rules = $this->tarifRules($type);
        $validated = $request->validate($rules);
        $this->assertNotChildTpa($validated['insurer_id']);
        return $this->ok($this->service->storeTarif($type, $validated), 'Tarif disimpan', 201);
    }

    public function updateTarif(Request $request, string $type, string $id): JsonResponse
    {
        $this->assertTarifType($type);
        $rules = [
            'price'     => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ];
        if ($type === 'obat') {
            $rules['pos_kwitansi'] = ['nullable', \Illuminate\Validation\Rule::in(\App\Models\MedicationTariff::POS_VALUES)];
        }
        $validated = $request->validate($rules);
        return $this->ok($this->service->updateTarif($type, $id, $validated), 'Tarif diperbarui');
    }

    public function deleteTarif(string $type, string $id): JsonResponse
    {
        $this->assertTarifType($type);
        $this->service->deleteTarif($type, $id);
        return $this->ok(null, 'Tarif dihapus');
    }

    // =========================================================================
    // METODE BAYAR — detail + CSV per insurer per type
    // =========================================================================

    public function showMetodeBayar(string $id): JsonResponse
    {
        return $this->ok($this->service->showMetodeBayar($id));
    }

    public function templateMetodeBayarCsv(Request $request, string $id, string $type): Response
    {
        $this->assertTarifType($type);
        // Validasi insurer ada
        \App\Models\Insurer::findOrFail($id);
        $csv = $this->service->templateTarifCsv($type);
        return $this->csvOrXlsxResponse($request, $csv, "template-tarif-{$type}", 'Template Tarif');
    }

    public function exportMetodeBayarCsv(Request $request, string $id, string $type): Response
    {
        $this->assertTarifType($type);
        $insurer = \App\Models\Insurer::findOrFail($id);
        // Child: ambil tarif dari parent (inheritance)
        $targetId = $insurer->tariffInsurerId();
        $csv = $this->service->exportTarifCsvForInsurer($type, $targetId);
        $code = $insurer->code ?? substr($insurer->id, 0, 8);
        return $this->csvOrXlsxResponse($request, $csv, "tarif-{$type}-{$code}-" . now()->format('Ymd'), 'Tarif');
    }

    public function importMetodeBayarCsv(Request $request, string $id, string $type): JsonResponse
    {
        $this->assertTarifType($type);
        $insurer = \App\Models\Insurer::findOrFail($id);
        if ($insurer->isChildTpa()) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Insurer child mewarisi tarif dari TPA parent — import tidak diizinkan.',
                'errors'  => null,
            ], 422);
        }
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120']);
        $csv = \App\Support\SpreadsheetHelper::fileToCsv($request->file('file'));
        $result = $this->service->importTarifCsvForInsurer($type, $insurer->id, $csv);
        return $this->ok(
            $result,
            "Import selesai: {$result['inserted']} baru, {$result['updated']} update, {$result['skipped']} dilewati."
        );
    }

    /**
     * Kembalikan CSV string sebagai file CSV (default) atau XLSX bila ?format=xlsx.
     * Logika data tetap di service (berbasis CSV); ini hanya adapter format.
     */
    private function csvOrXlsxResponse(Request $request, string $csv, string $baseName, string $sheetTitle): Response
    {
        if (strtolower((string) $request->query('format')) === 'xlsx') {
            $xlsx = \App\Support\SpreadsheetHelper::csvToXlsx($csv, $sheetTitle);
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

    /** Endpoint helper untuk frontend: harga master live per item. */
    public function masterPrice(string $type, string $itemId): JsonResponse
    {
        $this->assertTarifType($type);
        return $this->ok(['price' => $this->service->getMasterPriceFor($type, $itemId)]);
    }

    // =========================================================================
    // PAKET BEDAH — CRUD
    // =========================================================================

    public function indexPaket(Request $request): JsonResponse
    {
        return $this->ok($this->service->indexPaket(
            $request->only(['search', 'category', 'active', 'per_page'])
        ));
    }

    public function showPaket(string $id): JsonResponse
    {
        return $this->ok($this->service->showPaket($id));
    }

    public function storePaket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'               => 'required|string|max:255',
            'code'               => 'nullable|string|max:50|unique:surgery_packages,code',
            'package_type'       => 'nullable|in:BEDAH,PEMERIKSAAN',
            'category'           => 'nullable|string|max:100',
            'surgery_type'       => 'nullable|in:KATARAK,VITREORETINA,GLAUKOMA,LAINNYA',
            'description'        => 'nullable|string|max:1000',
            'keterangan'         => 'nullable|string|max:500',
            'estimated_duration' => 'nullable|integer|min:0',
            'price'              => 'nullable|numeric|min:0',
            'is_active'          => 'nullable|boolean',
        ]);
        // Auto-saran: bila admin tak memilih Jenis Bedah, tebak dari nama paket
        // (kolom eksplisit tetap sumber kebenaran — admin dapat ubah via edit).
        if (empty($validated['surgery_type'])) {
            $validated['surgery_type'] = \App\Models\SurgeryPackage::suggestSurgeryType($validated['name'] ?? null);
        }
        return $this->ok($this->service->storePaket($validated), 'Paket bedah dibuat', 201);
    }

    public function updatePaket(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'package_type'       => 'nullable|in:BEDAH,PEMERIKSAAN',
            'category'           => 'nullable|string|max:100',
            'surgery_type'       => 'nullable|in:KATARAK,VITREORETINA,GLAUKOMA,LAINNYA',
            'description'        => 'nullable|string|max:1000',
            'keterangan'         => 'nullable|string|max:500',
            'estimated_duration' => 'nullable|integer|min:0',
            'price'              => 'nullable|numeric|min:0',
            'is_active'          => 'nullable|boolean',
            // Manfaat "kontrol gratis pasca-bedah" (Opsi B). followup_procedure_id NULL = hapus manfaat.
            'followup_procedure_id' => 'sometimes|nullable|uuid|exists:procedures,id',
            'followup_count'        => 'sometimes|nullable|integer|min:0|max:20',
            'followup_valid_days'   => 'sometimes|nullable|integer|min:0|max:3650',
        ]);
        return $this->ok($this->service->updatePaket($id, $validated), 'Paket bedah diperbarui');
    }

    public function deletePaket(string $id): JsonResponse
    {
        $this->service->deletePaket($id);
        return $this->ok(null, 'Paket bedah dihapus');
    }

    // =========================================================================
    // PAKET BEDAH — ITEMS
    // =========================================================================

    public function indexItems(string $id): JsonResponse
    {
        return $this->ok($this->service->listItems($id));
    }

    public function addItem(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'item_type'     => 'required|in:' . implode(',', SurgeryPackageItem::TYPES),
            'item_id'       => 'required|uuid',
            'quantity'      => 'nullable|integer|min:1',
            'default_price' => 'nullable|numeric|min:0',
            'notes'         => 'nullable|string|max:500',
        ]);
        return $this->ok($this->service->addItem($id, $validated), 'Item ditambahkan', 201);
    }

    public function updateItem(Request $request, string $id, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            // Boleh ganti tipe+item saat edit (koreksi salah pilih), bukan cuma qty/harga.
            'item_type'     => 'sometimes|in:' . implode(',', SurgeryPackageItem::TYPES),
            'item_id'       => 'sometimes|uuid',
            'quantity'      => 'sometimes|integer|min:1',
            'default_price' => 'sometimes|numeric|min:0',
            'notes'         => 'nullable|string|max:500',
        ]);
        return $this->ok($this->service->updateItem($id, $itemId, $validated), 'Item diperbarui');
    }

    public function removeItem(string $id, string $itemId): JsonResponse
    {
        $this->service->removeItem($id, $itemId);
        return $this->ok(null, 'Item dihapus');
    }

    // =========================================================================
    // PAKET BEDAH — CSV TEMPLATE / EXPORT / IMPORT
    // =========================================================================

    public function templatePaketCsv(Request $request): Response
    {
        $csv = $this->service->templatePaketCsv();
        return $this->csvOrXlsxResponse($request, $csv, 'template-paket-bedah', 'Template Paket');
    }

    public function exportPaketCsv(Request $request): Response
    {
        $csv = $this->service->exportPaketCsv();
        return $this->csvOrXlsxResponse($request, $csv, 'paket-bedah-' . now()->format('Ymd'), 'Paket Bedah');
    }

    public function importPaketCsv(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls,ods|max:5120']);
        $csv = \App\Support\SpreadsheetHelper::fileToCsv($request->file('file'));
        $result = $this->service->importPaketCsv($csv);
        $msg = "Import selesai: {$result['created']} paket baru, {$result['updated']} paket diperbarui, "
             . "{$result['items_inserted']} item dimasukkan"
             . ($result['items_lookup_fail'] > 0 ? ", {$result['items_lookup_fail']} item gagal lookup" : '')
             . '.';
        return $this->ok($result, $msg);
    }

    /** Template CSV komposisi SATU paket (header notes + item paket ini terisi). */
    public function templatePaketCsvForPackage(Request $request, string $id): Response
    {
        $csv = $this->service->templatePaketCsvForPackage($id);
        return $this->csvOrXlsxResponse($request, $csv, 'template-paket-' . $id, 'Template Paket');
    }

    /** Export komposisi SATU paket apa adanya. */
    public function exportPaketCsvForPackage(Request $request, string $id): Response
    {
        $csv = $this->service->exportPaketCsvForPackage($id);
        return $this->csvOrXlsxResponse($request, $csv, 'paket-' . $id . '-' . now()->format('Ymd'), 'Paket');
    }

    // =========================================================================
    // PAKET BEDAH — TARIFFS (harga jual per penjamin)
    // =========================================================================

    public function indexTariffs(string $id): JsonResponse
    {
        return $this->ok($this->service->listTariffs($id));
    }

    public function upsertTariff(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            // id diisi saat EDIT varian tertentu (kini boleh >1 tarif per penjamin).
            'id'               => 'nullable|uuid|exists:surgery_package_tariffs,id',
            'insurer_id'       => 'nullable|uuid|exists:insurers,id',
            'display_name'     => 'nullable|string|max:150',
            'price_mode'       => 'nullable|in:NOMINAL,PERSEN',
            'sell_price'       => 'required_if:price_mode,NOMINAL|nullable|numeric|min:0',
            'discount_percent' => 'required_if:price_mode,PERSEN|nullable|numeric|min:0|max:100',
            'is_active'        => 'nullable|boolean',
            // Item OVERRIDE varian (scope: IOL) — mengganti IOL komposisi saat snapshot;
            // array kosong = hapus semua override varian ini (replace-all).
            'override_items'              => 'nullable|array|max:5',
            'override_items.*.item_type'  => 'nullable|in:IOL',
            'override_items.*.item_id'    => 'required_with:override_items|uuid|exists:iol_items,id',
            'override_items.*.quantity'   => 'nullable|integer|min:1',
        ]);
        return $this->ok($this->service->upsertTariff($id, $validated), 'Tarif paket disimpan');
    }

    public function deleteTariff(string $id, string $tariffId): JsonResponse
    {
        $this->service->deleteTariff($id, $tariffId);
        return $this->ok(null, 'Tarif paket dihapus');
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    private function assertTarifType(string $type): void
    {
        if (! in_array($type, self::TARIF_TYPES, true)) {
            abort(404);
        }
    }

    private function tarifRules(string $type): array
    {
        $fkRules = match ($type) {
            'tindakan' => ['procedure_id'  => 'required|uuid|exists:procedures,id'],
            'obat'     => ['medication_id' => 'required|uuid|exists:medications,id'],
            'bhp'      => ['bhp_item_id'   => 'required|uuid|exists:bhp_items,id'],
            'iol'      => ['iol_item_id'   => 'required|uuid|exists:iol_items,id'],
        };

        $rules = $fkRules + [
            'insurer_id' => 'required|uuid|exists:insurers,id',
            'price'      => 'required|numeric|min:0',
            'is_active'  => 'nullable|boolean',
        ];

        // Pos kwitansi obat (Obat Pulang/Tindakan/Injeksi) — hanya untuk type obat.
        if ($type === 'obat') {
            $rules['pos_kwitansi'] = ['nullable', \Illuminate\Validation\Rule::in(\App\Models\MedicationTariff::POS_VALUES)];
        }

        return $rules;
    }

    private function assertNotChildTpa(string $insurerId): void
    {
        $insurer = \App\Models\Insurer::findOrFail($insurerId);
        if ($insurer->isChildTpa()) {
            abort(422, 'Insurer child mewarisi tarif dari TPA parent. Tambah tarif di parent.');
        }
    }

    private function ok(mixed $data, string $message = 'Berhasil', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => null,
        ], $status);
    }
}
