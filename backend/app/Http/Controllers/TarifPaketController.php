<?php

namespace App\Http\Controllers;

use App\Models\SurgeryPackageItem;
use App\Models\SurgeryPackageTariff;
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
            $request->only(['insurer_id', 'per_page'])
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
        $validated = $request->validate([
            'price'     => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
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

    public function templateMetodeBayarCsv(string $id, string $type): Response
    {
        $this->assertTarifType($type);
        // Validasi insurer ada
        \App\Models\Insurer::findOrFail($id);
        $csv = $this->service->templateTarifCsv($type);
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"template-tarif-{$type}.csv\"",
        ]);
    }

    public function exportMetodeBayarCsv(string $id, string $type): Response
    {
        $this->assertTarifType($type);
        $insurer = \App\Models\Insurer::findOrFail($id);
        // Child: ambil tarif dari parent (inheritance)
        $targetId = $insurer->tariffInsurerId();
        $csv = $this->service->exportTarifCsvForInsurer($type, $targetId);
        $code = $insurer->code ?? substr($insurer->id, 0, 8);
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"tarif-{$type}-{$code}-" . now()->format('Ymd') . ".csv\"",
        ]);
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
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
        $csv = file_get_contents($request->file('file')->getRealPath());
        $result = $this->service->importTarifCsvForInsurer($type, $insurer->id, $csv);
        return $this->ok(
            $result,
            "Import selesai: {$result['inserted']} baru, {$result['updated']} update, {$result['skipped']} dilewati."
        );
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
            'category'           => 'nullable|string|max:100',
            'description'        => 'nullable|string|max:1000',
            'keterangan'         => 'nullable|string|max:500',
            'estimated_duration' => 'nullable|integer|min:0',
            'price'              => 'nullable|numeric|min:0',
            'is_active'          => 'nullable|boolean',
        ]);
        return $this->ok($this->service->storePaket($validated), 'Paket bedah dibuat', 201);
    }

    public function updatePaket(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'category'           => 'nullable|string|max:100',
            'description'        => 'nullable|string|max:1000',
            'keterangan'         => 'nullable|string|max:500',
            'estimated_duration' => 'nullable|integer|min:0',
            'price'              => 'nullable|numeric|min:0',
            'is_active'          => 'nullable|boolean',
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

    public function templatePaketCsv(): Response
    {
        $csv = $this->service->templatePaketCsv();
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template-paket-bedah.csv"',
        ]);
    }

    public function exportPaketCsv(): Response
    {
        $csv = $this->service->exportPaketCsv();
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="paket-bedah-' . now()->format('Ymd') . '.csv"',
        ]);
    }

    public function importPaketCsv(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
        $csv = file_get_contents($request->file('file')->getRealPath());
        $result = $this->service->importPaketCsv($csv);
        $msg = "Import selesai: {$result['created']} paket baru, {$result['updated']} paket diperbarui, "
             . "{$result['items_inserted']} item dimasukkan"
             . ($result['items_lookup_fail'] > 0 ? ", {$result['items_lookup_fail']} item gagal lookup" : '')
             . '.';
        return $this->ok($result, $msg);
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
            'insurer_id'     => 'nullable|uuid|exists:insurers,id',
            'classification' => 'required|in:' . implode(',', SurgeryPackageTariff::CLASSIFICATIONS),
            'sell_price'     => 'required|numeric|min:0',
            'is_active'      => 'nullable|boolean',
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

        return $fkRules + [
            'insurer_id' => 'required|uuid|exists:insurers,id',
            'price'      => 'required|numeric|min:0',
            'is_active'  => 'nullable|boolean',
        ];
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
