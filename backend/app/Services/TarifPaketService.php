<?php

namespace App\Services;

use App\Models\BhpItem;
use App\Models\IolItem;
use App\Models\Medication;
use App\Models\Procedure;
use App\Models\SurgeryPackage;
use App\Models\SurgeryPackageItem;
use App\Models\SurgeryPackageTariff;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TarifPaketService
{
    public function __construct(
        private readonly Request $request,
        private readonly MasterDataService $masterData,
    ) {}

    // =========================================================================
    // TARIF PER PENJAMIN — delegasi ke MasterDataService (reuse, no duplication)
    // =========================================================================

    public function indexTarif(string $type, array $filters = []): LengthAwarePaginator
    {
        return $this->masterData->indexTarif($type, $filters);
    }

    public function storeTarif(string $type, array $data): mixed
    {
        return $this->masterData->storeTarif($type, $data);
    }

    public function updateTarif(string $type, string $id, array $data): mixed
    {
        return $this->masterData->updateTarif($type, $id, $data);
    }

    public function deleteTarif(string $type, string $id): void
    {
        $this->masterData->deleteTarif($type, $id);
    }

    public function showMetodeBayar(string $id): array
    {
        return $this->masterData->showMetodeBayar($id);
    }

    public function templateTarifCsv(string $type): string
    {
        return $this->masterData->templateTarifCsv($type);
    }

    public function exportTarifCsvForInsurer(string $type, string $insurerId): string
    {
        return $this->masterData->exportTarifCsvForInsurer($type, $insurerId);
    }

    public function importTarifCsvForInsurer(string $type, string $insurerId, string $csv): array
    {
        return $this->masterData->importTarifCsvForInsurer($type, $insurerId, $csv);
    }

    /** Expose harga master untuk satu item (dipakai endpoint master-price). */
    public function getMasterPriceFor(string $type, string $itemId): float
    {
        $modelType = match ($type) {
            'tindakan' => SurgeryPackageItem::TYPE_PROCEDURE,
            'obat'     => SurgeryPackageItem::TYPE_MEDICATION,
            'bhp'      => SurgeryPackageItem::TYPE_BHP,
            'iol'      => SurgeryPackageItem::TYPE_IOL,
            default    => throw new \Exception("Tipe tidak dikenal: {$type}", 422),
        };
        return $this->resolveMasterPrice($modelType, $itemId);
    }


    // =========================================================================
    // PAKET BEDAH — CRUD utama
    // =========================================================================

    public function indexPaket(array $filters = []): LengthAwarePaginator
    {
        $query = SurgeryPackage::query()->with(['items', 'packageTariffs.insurer']);

        if (! empty($filters['search'])) {
            $kw = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$kw}%")
                ->orWhere('code', 'ilike', "%{$kw}%")
                ->orWhere('category', 'ilike', "%{$kw}%")
            );
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['package_type'])) {
            $query->where('package_type', $filters['package_type']);
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }

        return $query->orderBy('name')->paginate($filters['per_page'] ?? 20);
    }

    public function showPaket(string $id): SurgeryPackage
    {
        $pkg = SurgeryPackage::with(['items', 'packageTariffs.insurer', 'packageTariffs.overrideItems'])->findOrFail($id);

        // Enrich items dengan info nama/kode item terkait. withTrashed: master yang
        // sudah dihapus tetap tampil bernama (suffix "(terhapus)"), bukan "-".
        $this->preloadMedicationPos(
            $pkg->items->where('item_type', SurgeryPackageItem::TYPE_MEDICATION)->pluck('item_id')->all()
        );
        $pkg->items->each(function (SurgeryPackageItem $item) {
            $resolved = $item->resolveItemWithTrashed();
            $item->setAttribute('item_name', $this->displayItemName($resolved));
            $item->setAttribute('item_code', $resolved?->code ?? null);
            $item->setAttribute('item_category', $this->resolveItemCategory($item->item_type, $resolved));
            $item->setAttribute('subtotal', $item->subtotal());
        });

        // Enrich tariffs dengan diskon yang dihitung
        $pkg->packageTariffs->each(function (SurgeryPackageTariff $t) use ($pkg) {
            $t->setRelation('package', $pkg);
            // Tangkap %-diskon INPUT (mode PERSEN) + mode SEBELUM discount_percent di-clobber
            // oleh nilai computed di bawah (computed = diskon thd base, utk tampilan).
            $t->setAttribute('input_discount_pct', $t->discount_percent);
            $t->setAttribute('price_mode', $t->discount_percent !== null ? 'PERSEN' : 'NOMINAL');
            $t->setAttribute('discount_amount', $t->discountAmount());
            $t->setAttribute('discount_percent', $t->discountPercent());
            // Item override varian (IOL) + nama tampil — utk seksi "IOL Varian" modal tarif.
            // unsetRelation WAJIB: relasi overrideItems ikut serialisasi sbg key
            // 'override_items' (snake_case) dan MENIMPA atribut enriched ini.
            $t->setAttribute('override_items', $this->tariffOverrideItems($t));
            $t->unsetRelation('overrideItems');
        });

        // Manfaat "kontrol gratis pasca-bedah" (Opsi B): nama prosedur konsultasi untuk kartu UI.
        if ($pkg->followup_procedure_id) {
            $pkg->setAttribute(
                'followup_procedure_name',
                \App\Models\Procedure::find($pkg->followup_procedure_id)?->name
            );
        }

        return $pkg;
    }

    public function storePaket(array $data): SurgeryPackage
    {
        $pkg = SurgeryPackage::create($data + ['total_base_price' => 0]);
        $this->log(auth('api')->id(), 'CREATE_PAKET_BEDAH', SurgeryPackage::class, $pkg->id);
        return $pkg;
    }

    public function updatePaket(string $id, array $data): SurgeryPackage
    {
        $pkg = SurgeryPackage::findOrFail($id);

        // Normalisasi kartu "Manfaat Kontrol Pasca-Bedah" (Opsi B): tanpa prosedur =
        // tanpa manfaat (count 0, valid null); dengan prosedur = minimal 1 jatah.
        if (array_key_exists('followup_procedure_id', $data)) {
            if (empty($data['followup_procedure_id'])) {
                $data['followup_procedure_id'] = null;
                $data['followup_count']        = 0;
                $data['followup_valid_days']   = null;
            } else {
                $data['followup_count'] = max(1, (int) ($data['followup_count'] ?? 1));
            }
        }

        $pkg->update($data);
        $this->log(auth('api')->id(), 'UPDATE_PAKET_BEDAH', SurgeryPackage::class, $id);
        return $pkg->fresh();
    }

    public function deletePaket(string $id): void
    {
        SurgeryPackage::findOrFail($id)->delete();
        $this->log(auth('api')->id(), 'DELETE_PAKET_BEDAH', SurgeryPackage::class, $id);
    }

    // =========================================================================
    // PAKET BEDAH — ITEMS (komposisi paket)
    // =========================================================================

    public function listItems(string $packageId): array
    {
        $pkg   = SurgeryPackage::findOrFail($packageId);
        $items = $pkg->items()->get();
        $this->preloadMedicationPos(
            $items->where('item_type', SurgeryPackageItem::TYPE_MEDICATION)->pluck('item_id')->all()
        );
        return $items->map(function (SurgeryPackageItem $item) {
            $resolved = $item->resolveItemWithTrashed();
            return [
                'id'            => $item->id,
                'item_type'     => $item->item_type,
                'item_id'       => $item->item_id,
                'item_code'     => $resolved?->code ?? null,
                'item_name'     => $this->displayItemName($resolved),
                'item_category' => $this->resolveItemCategory($item->item_type, $resolved),
                'quantity'      => $item->quantity,
                'default_price' => $item->default_price,
                'subtotal'      => $item->subtotal(),
                'notes'         => $item->notes,
            ];
        })->toArray();
    }

    /**
     * Tambah item ke paket. Snapshot default_price diambil dari master
     * (base_price/price) jika data['default_price'] tidak disediakan.
     */
    public function addItem(string $packageId, array $data): SurgeryPackageItem
    {
        $pkg = SurgeryPackage::findOrFail($packageId);

        // Paket PEMERIKSAAN (poliklinik) boleh komponen TINDAKAN (PROCEDURE) + OBAT
        // (MEDICATION) — bundel pemeriksaan umum tindakan+obat. BHP/IOL tak relevan.
        if ($pkg->package_type === SurgeryPackage::TYPE_PEMERIKSAAN
            && ! in_array($data['item_type'], [SurgeryPackageItem::TYPE_PROCEDURE, SurgeryPackageItem::TYPE_MEDICATION], true)) {
            throw new \Exception('Paket pemeriksaan hanya boleh berisi Tindakan atau Obat.', 422);
        }

        $defaultPrice = $data['default_price'] ?? $this->resolveMasterPrice($data['item_type'], $data['item_id']);

        return DB::transaction(function () use ($pkg, $data, $defaultPrice) {
            $item = SurgeryPackageItem::updateOrCreate(
                [
                    'surgery_package_id' => $pkg->id,
                    'item_type'          => $data['item_type'],
                    'item_id'            => $data['item_id'],
                ],
                [
                    'quantity'      => $data['quantity'] ?? 1,
                    'default_price' => $defaultPrice,
                    'notes'         => $data['notes'] ?? null,
                ]
            );

            $pkg->recalcTotalBasePrice();
            $this->log(auth('api')->id(), 'ADD_PAKET_ITEM', SurgeryPackageItem::class, $item->id, "package:{$pkg->id}");

            return $item;
        });
    }

    public function updateItem(string $packageId, string $itemId, array $data): SurgeryPackageItem
    {
        $pkg  = SurgeryPackage::findOrFail($packageId);
        $item = $pkg->items()->where('id', $itemId)->firstOrFail();

        // Edit boleh GANTI tipe+item (koreksi salah pilih) — bukan cuma qty/harga.
        $newType = $data['item_type'] ?? $item->item_type;
        $newId   = $data['item_id']   ?? $item->item_id;
        if ($newType !== $item->item_type || $newId !== $item->item_id) {
            // Paket PEMERIKSAAN hanya Tindakan/Obat (samakan dgn addItem).
            if ($pkg->package_type === SurgeryPackage::TYPE_PEMERIKSAAN
                && ! in_array($newType, [SurgeryPackageItem::TYPE_PROCEDURE, SurgeryPackageItem::TYPE_MEDICATION], true)) {
                throw new \Exception('Paket pemeriksaan hanya boleh berisi Tindakan atau Obat.', 422);
            }
            // Cegah tabrakan unique (paket, tipe, item) dengan baris lain.
            $dupe = $pkg->items()
                ->where('item_type', $newType)->where('item_id', $newId)
                ->where('id', '!=', $item->id)->exists();
            if ($dupe) {
                throw new \Exception('Item tersebut sudah ada di paket.', 422);
            }
        }

        return DB::transaction(function () use ($pkg, $item, $data, $newType, $newId) {
            $item->update(array_filter([
                'item_type'     => $newType,
                'item_id'       => $newId,
                'quantity'      => $data['quantity']      ?? null,
                'default_price' => $data['default_price'] ?? null,
                'notes'         => $data['notes']         ?? null,
            ], fn ($v) => $v !== null));

            $pkg->recalcTotalBasePrice();
            $this->log(auth('api')->id(), 'UPDATE_PAKET_ITEM', SurgeryPackageItem::class, $item->id);

            return $item->fresh();
        });
    }

    public function removeItem(string $packageId, string $itemId): void
    {
        $pkg  = SurgeryPackage::findOrFail($packageId);
        $item = $pkg->items()->where('id', $itemId)->firstOrFail();

        DB::transaction(function () use ($pkg, $item) {
            $item->delete();
            $pkg->recalcTotalBasePrice();
            $this->log(auth('api')->id(), 'DELETE_PAKET_ITEM', SurgeryPackageItem::class, $item->id);
        });
    }

    /**
     * Kategori item utk tampilan format Buku Tarif ("Kategori - Nama Item") — label
     * SAMA dengan grouping kwitansi (billing_categories): Procedure pakai `category`
     * master (sudah = kategori buku tarif); BHP di-map dari enum internal
     * (MEDICAL_BHP → BAHAN HABIS PAKAI dst); Medication pakai pos kwitansi Buku Tarif
     * (Obat Tindakan/Pulang/Injeksi, fallback 'Obat'); IOL → 'IOL'.
     * Null bila tak ada → FE fallback ke label tipe.
     */
    private function resolveItemCategory(string $type, ?\Illuminate\Database\Eloquent\Model $resolved): ?string
    {
        if (! $resolved) {
            return null;
        }
        return match ($type) {
            SurgeryPackageItem::TYPE_PROCEDURE  => $resolved->category ?: null,
            SurgeryPackageItem::TYPE_BHP        => BhpItem::billingCategoryLabel($resolved->category),
            SurgeryPackageItem::TYPE_MEDICATION => $this->medicationPosLabel($resolved->id),
            SurgeryPackageItem::TYPE_IOL        => 'IOL',
            default                             => null,
        };
    }

    /**
     * Nama tampilan item master (IOL pakai brand). Master soft-deleted → tetap
     * bernama + suffix "(terhapus)"; benar-benar tak ada → '-'.
     */
    private function displayItemName(?\Illuminate\Database\Eloquent\Model $resolved): string
    {
        $name = $resolved?->name ?? $resolved?->brand;
        if ($name === null) {
            return '-';
        }
        return $resolved->deleted_at ? "{$name} (terhapus)" : $name;
    }

    /** Cache pos kwitansi per medication_id (per-request) — isi via preload/lazy. */
    private array $medPosCache = [];

    /**
     * Label pos kwitansi Buku Tarif (baris tarif UMUM) utk satu obat. Batch dulu via
     * preloadMedicationPos() di jalur list agar tidak N+1; tanpa baris tarif → 'Obat'.
     */
    private function medicationPosLabel(string $medicationId): string
    {
        if (! array_key_exists($medicationId, $this->medPosCache)) {
            $this->preloadMedicationPos([$medicationId]);
        }
        $pos = $this->medPosCache[$medicationId];
        return $pos ? \App\Models\MedicationTariff::posLabel($pos) : 'Obat';
    }

    /** Ambil pos_kwitansi tarif UMUM banyak obat sekaligus ke cache (1 query). */
    private function preloadMedicationPos(array $medicationIds): void
    {
        $missing = array_values(array_diff(array_unique($medicationIds), array_keys($this->medPosCache)));
        if (! $missing) {
            return;
        }
        $umumId = \App\Models\Insurer::where('is_system', true)->where('type', 'UMUM')->value('id');
        $rows = $umumId
            ? DB::table('medication_tariffs')->whereIn('medication_id', $missing)
                ->where('insurer_id', $umumId)->where('is_active', true)
                ->pluck('pos_kwitansi', 'medication_id')->all()
            : [];
        foreach ($missing as $id) {
            $this->medPosCache[$id] = $rows[$id] ?? null;
        }
    }

    /**
     * Lihat harga master "live" untuk satu item (PROCEDURE/MEDICATION/BHP/IOL).
     * Dipakai sebagai default ketika admin belum override default_price.
     *
     * Sumber harga utama PASCA-refactor "Buku Tarif": harga jual penjamin sistem UMUM
     * (mis. procedures.base_price kini 0 — harga jual pindah ke procedure_tariffs). Bila
     * item belum punya baris Buku Tarif (mis. sebagian obat), fallback ke kolom harga
     * master lama agar snapshot paket tetap masuk akal (tidak 0).
     */
    private function resolveMasterPrice(string $type, string $itemId): float
    {
        $bukuTarif = $this->resolveBukuTarifUmumPrice($type, $itemId);
        if ($bukuTarif > 0) {
            return $bukuTarif;
        }

        return match ($type) {
            SurgeryPackageItem::TYPE_PROCEDURE  => (float) (Procedure::find($itemId)?->base_price  ?? 0),
            SurgeryPackageItem::TYPE_MEDICATION => (float) (Medication::find($itemId)?->price      ?? 0),
            SurgeryPackageItem::TYPE_BHP        => (float) (BhpItem::find($itemId)?->price         ?? 0),
            SurgeryPackageItem::TYPE_IOL        => (float) (IolItem::find($itemId)?->price         ?? 0),
            default                             => 0,
        };
    }

    /**
     * Harga jual penjamin sistem UMUM dari Buku Tarif (procedure/medication/bhp/iol_tariffs).
     * Sejalan dengan KasirService::getPrice (level fallback UMUM). 0 bila tak ada baris tarif.
     */
    private function resolveBukuTarifUmumPrice(string $type, string $itemId): float
    {
        [$table, $fk] = match ($type) {
            SurgeryPackageItem::TYPE_PROCEDURE  => ['procedure_tariffs',  'procedure_id'],
            SurgeryPackageItem::TYPE_MEDICATION => ['medication_tariffs', 'medication_id'],
            SurgeryPackageItem::TYPE_BHP        => ['bhp_tariffs',        'bhp_item_id'],
            SurgeryPackageItem::TYPE_IOL        => ['iol_tariffs',        'iol_item_id'],
            default                             => [null, null],
        };
        if (! $table) {
            return 0;
        }

        $umumId = \App\Models\Insurer::where('is_system', true)->where('type', 'UMUM')->value('id');
        if (! $umumId) {
            return 0;
        }

        $price = DB::table($table)
            ->where($fk, $itemId)
            ->where('insurer_id', $umumId)
            ->where('is_active', true)
            ->value('price');

        return $price !== null ? (float) $price : 0.0;
    }

    // =========================================================================
    // PAKET BEDAH — TARIFFS (harga jual per penjamin + auto diskon)
    // =========================================================================

    public function listTariffs(string $packageId): array
    {
        $pkg = SurgeryPackage::findOrFail($packageId);
        return $pkg->packageTariffs()->with('insurer', 'overrideItems')->get()->map(function (SurgeryPackageTariff $t) use ($pkg) {
            $t->setRelation('package', $pkg);
            return [
                'id'                => $t->id,
                'insurer_id'        => $t->insurer_id,
                'insurer_name'      => $t->insurer?->name ?? 'SEMUA',
                'display_name'      => $t->display_name,
                'price_mode'        => $t->discount_percent !== null ? 'PERSEN' : 'NOMINAL',
                'sell_price'        => $t->sell_price,
                'base_price'        => $pkg->total_base_price,
                'discount_amount'   => $t->discountAmount(),
                'discount_percent'  => $t->discountPercent(),
                // %-diskon yang DIINPUT admin (mode PERSEN); beda dari discount_percent terhitung.
                'input_discount_pct' => $t->discount_percent,
                'is_active'         => $t->is_active,
                'override_items'    => $this->tariffOverrideItems($t),
            ];
        })->toArray();
    }

    /**
     * Bentuk array item override varian (scope: IOL) + nama tampil — dipakai
     * listTariffs & showPaket. withTrashed: IOL terhapus tetap bernama "(terhapus)".
     */
    private function tariffOverrideItems(SurgeryPackageTariff $t): array
    {
        return $t->overrideItems->map(function ($ov) {
            $iol  = IolItem::withTrashed()->find($ov->item_id);
            $name = '-';
            if ($iol) {
                $power = $iol->power !== null
                    ? rtrim(rtrim(number_format((float) $iol->power, 2, '.', ''), '0'), '.') . 'D'
                    : '';
                $name = trim("{$iol->brand} {$iol->model} {$power}") . ($iol->deleted_at ? ' (terhapus)' : '');
            }
            return [
                'id'        => $ov->id,
                'item_type' => $ov->item_type,
                'item_id'   => $ov->item_id,
                'quantity'  => $ov->quantity,
                'item_name' => $name,
            ];
        })->values()->toArray();
    }

    public function upsertTariff(string $packageId, array $data): SurgeryPackageTariff
    {
        $pkg = SurgeryPackage::findOrFail($packageId);

        // Banyak varian per (paket, penjamin) DIIZINKAN (post 2026_07_12). Identitas baris
        // = id eksplisit. Edit → muat baris itu (scoped paket, withTrashed→restore). Tambah
        // → SELALU buat baris baru (tak lagi gabung-per-insurer) agar UMUM bisa punya >1
        // varian (mis. "Phaco Mandalika" & "Phaco Osaka"). insurer_id NULL = "SEMUA".
        $insurerId = $data['insurer_id'] ?? null;
        $existing  = ! empty($data['id'])
            ? SurgeryPackageTariff::withTrashed()
                ->where('surgery_package_id', $pkg->id)
                ->where('id', $data['id'])
                ->first()
            : null;

        // Harga: mode PERSEN → sell_price dihitung dari total_base_price paket; NOMINAL →
        // sell_price langsung. sell_price selalu jadi sumber tunggal billing.
        $mode = $data['price_mode'] ?? 'NOMINAL';
        if ($mode === 'PERSEN') {
            $pct    = (float) ($data['discount_percent'] ?? 0);
            $values = [
                'discount_percent' => $pct,
                'sell_price'       => round((float) $pkg->total_base_price * (1 - $pct / 100), 2),
            ];
        } else {
            $values = ['sell_price' => (float) $data['sell_price'], 'discount_percent' => null];
        }
        $values['display_name'] = $data['display_name'] ?? null;
        $values['is_active']    = $data['is_active'] ?? true;

        $tariff = DB::transaction(function () use ($pkg, $insurerId, $existing, $values, $data) {
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update($values);
                $tariff = $existing;
            } else {
                $tariff = SurgeryPackageTariff::create(
                    ['surgery_package_id' => $pkg->id, 'insurer_id' => $insurerId] + $values
                );
            }

            // Item OVERRIDE varian (scope: IOL) — replace-all bila key dikirim; array
            // kosong = hapus semua override (varian kembali murni harga/label).
            if (array_key_exists('override_items', $data)) {
                $tariff->overrideItems()->delete();
                foreach (($data['override_items'] ?? []) as $row) {
                    if (empty($row['item_id'])) {
                        continue;
                    }
                    $tariff->overrideItems()->create([
                        'item_type' => 'IOL',   // scope saat ini IOL saja (validasi controller)
                        'item_id'   => $row['item_id'],
                        'quantity'  => max(1, (int) ($row['quantity'] ?? 1)),
                    ]);
                }
            }

            return $tariff;
        });

        $this->log(auth('api')->id(), 'UPSERT_PAKET_TARIFF', SurgeryPackageTariff::class, $tariff->id, "package:{$pkg->id}");
        return $tariff->load('insurer');
    }

    public function deleteTariff(string $packageId, string $tariffId): void
    {
        $pkg    = SurgeryPackage::findOrFail($packageId);
        $tariff = $pkg->packageTariffs()->where('id', $tariffId)->firstOrFail();
        $tariff->delete();
        $this->log(auth('api')->id(), 'DELETE_PAKET_TARIFF', SurgeryPackageTariff::class, $tariffId);
    }

    // =========================================================================
    // PAKET BEDAH — CSV TEMPLATE / EXPORT / IMPORT
    // =========================================================================

    private const CSV_HEADERS = ['nama_paket', 'kategori', 'tipe_paket', 'deskripsi', 'aktif', 'item_tipe', 'item_kategori', 'item_nama', 'qty', 'harga', 'catatan'];

    public function templatePaketCsv(): string
    {
        $output = fopen('php://temp', 'r+');
        foreach ($this->paketCsvNotes() as $note) {
            fwrite($output, '# ' . $note . "\n");
        }
        fputcsv($output, self::CSV_HEADERS, ',', '"', '\\');
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    public function exportPaketCsv(): string
    {
        $packages = SurgeryPackage::with('items')->orderBy('name')->get();
        return $this->buildPaketCsv($packages);
    }

    /**
     * Template CSV utk SATU paket — header notes + komposisi item paket ini
     * terisi (siap di-edit/import balik). Dipakai dari halaman detail paket.
     * Kalau paket belum punya item → 1 baris header (kolom item kosong).
     */
    public function templatePaketCsvForPackage(string $packageId): string
    {
        $pkg = SurgeryPackage::with('items')->findOrFail($packageId);
        return $this->buildPaketCsv(collect([$pkg]), withNotes: true);
    }

    /**
     * Export komposisi SATU paket apa adanya (tanpa baris petunjuk).
     */
    public function exportPaketCsvForPackage(string $packageId): string
    {
        $pkg = SurgeryPackage::with('items')->findOrFail($packageId);
        return $this->buildPaketCsv(collect([$pkg]));
    }

    /**
     * Tulis CSV paket (long format) dari koleksi paket. Optional baris petunjuk
     * "#" di atas header (template). Item master soft-deleted di-skip; paket
     * tanpa item valid tetap dapat 1 baris header.
     */
    private function buildPaketCsv($packages, bool $withNotes = false): string
    {
        $output = fopen('php://temp', 'r+');

        if ($withNotes) {
            foreach ($this->paketCsvNotes() as $note) {
                fwrite($output, '# ' . $note . "\n");
            }
        }

        fputcsv($output, self::CSV_HEADERS, ',', '"', '\\');

        foreach ($packages as $pkg) {
            $headerRow = [
                $pkg->name,
                $pkg->category ?? '',
                $pkg->package_type ?? SurgeryPackage::TYPE_BEDAH,
                $pkg->description ?? '',
                $pkg->is_active ? '1' : '0',
            ];

            $exportedItem = false;
            foreach ($pkg->items as $item) {
                $resolved = $item->resolveItem();
                // Master item sudah soft-deleted → resolveItem null → nama kosong.
                // Skip: kalau diekspor, import gagal "item_nama kosong" (export & import inkonsisten).
                if (! $resolved) {
                    continue;
                }
                $itemName = $this->formatItemNameForCsv($item->item_type, $resolved);
                $exportedItem = true;
                // item_kategori = nilai MENTAH master (category/golongan) — dipakai import
                // sebagai disambiguator lookupByNameCategory, BUKAN label tampilan kwitansi.
                $rawCat = match ($item->item_type) {
                    SurgeryPackageItem::TYPE_PROCEDURE,
                    SurgeryPackageItem::TYPE_BHP        => $resolved->category ?? '',
                    SurgeryPackageItem::TYPE_MEDICATION => $resolved->golongan ?? '',
                    default                             => '',
                };
                fputcsv($output, array_merge($headerRow, [
                    $item->item_type,
                    $rawCat,
                    $itemName,
                    (string) $item->quantity,
                    $this->formatPriceForCsv($item->default_price),                   // harga snapshot komposisi
                    $item->notes ?? '',
                ]), ',', '"', '\\');
            }

            // Paket tanpa item valid (kosong, atau semua master-nya sudah dihapus) →
            // tetap diekspor 1 baris header (kolom item kosong) supaya paket tak hilang.
            if (! $exportedItem) {
                fputcsv($output, array_merge($headerRow, ['', '', '', '', '', '']), ',', '"', '\\');
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    /** Baris petunjuk pengisian (tanpa prefix '#') untuk template paket bedah. */
    private function paketCsvNotes(): array
    {
        return [
            'PETUNJUK PENGISIAN — baris diawali "#" diabaikan saat import (boleh dibiarkan/dihapus).',
            'Format LONG: 1 baris = 1 item. Paket multi-item → ulang baris dgn nama_paket sama.',
            'Kolom WAJIB: nama_paket, item_tipe, item_nama, qty. (kategori/tipe_paket/deskripsi/aktif/item_kategori/harga/catatan opsional)',
            'tipe_paket: BEDAH (boleh PROCEDURE/MEDICATION/BHP/IOL) atau PEMERIKSAAN (hanya PROCEDURE). Kosong → BEDAH (paket baru) / dipertahankan (paket lama).',
            'item_tipe salah satu: ' . implode(' | ', SurgeryPackageItem::TYPES) . '.',
            'item_kategori = kategori buku tarif item — OPSIONAL, hanya untuk membedakan bila ada nama item kembar di kategori berbeda. Tindakan: mis. "Tarif Administrasi"/"CSSD"; Obat: golongan (mis. "KERAS"/"BEBAS").',
            'item_nama dicocokkan ke master (case-insensitive). IOL: tulis "Brand Model PowerD" mis. "Alcon AcrySof IQ 21D".',
            'Pos kwitansi obat (Obat Tindakan/Pulang/Injeksi) BUKAN di sini — itu atribut TARIF, diatur di Buku Tarif / Metode Bayar; komposisi paket cukup merujuk obatnya.',
            'qty = angka >= 1 (kosong/0 → dianggap 1). aktif: 1 = aktif, 0 = nonaktif.',
            'harga = harga snapshot komposisi (angka rupiah tanpa desimal, mis. 1500000). KOSONG/0 → otomatis ambil dari Buku Tarif (penjamin UMUM). Isi hanya bila ingin mengunci harga item berbeda dari master.',
            'Import nama_paket yang SUDAH ada → komposisi item di-REPLACE (tarif jual per penjamin TIDAK disentuh).',
        ];
    }

    /**
     * Import paket bedah dari CSV (long format).
     * Konflik nama paket → replace komposisi items (header dipertahankan, tarif jual tidak disentuh).
     * Item lookup case-insensitive by name (IOL: "brand model powerD").
     * default_price auto dari master saat ini.
     */
    public function importPaketCsv(string $csvContent): array
    {
        $records = \App\Support\SpreadsheetHelper::parseCsvRecords($csvContent);
        if (empty($records)) {
            throw new \Exception('File CSV kosong.', 422);
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($records));
        foreach (['nama_paket', 'item_tipe', 'item_nama', 'qty'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw new \Exception("Header CSV harus mengandung kolom '{$required}'.", 422);
            }
        }

        // Group baris by nama_paket
        $grouped = [];
        $errors  = [];
        foreach ($records as $idx => $values) {
            $lineNum = $idx + 2;
            if (count($values) !== count($headers)) {
                $errors[] = "Baris {$lineNum}: jumlah kolom tidak sesuai header";
                continue;
            }
            $row = array_combine($headers, $values);
            $namaPaket = trim((string) ($row['nama_paket'] ?? ''));
            if ($namaPaket === '') {
                $errors[] = "Baris {$lineNum}: 'nama_paket' kosong";
                continue;
            }

            // tipe_paket opsional: BEDAH | PEMERIKSAAN. Null = pertahankan (existing) / BEDAH (baru).
            $tipePaket = strtoupper(trim((string) ($row['tipe_paket'] ?? '')));
            if ($tipePaket !== '' && ! in_array($tipePaket, [SurgeryPackage::TYPE_BEDAH, SurgeryPackage::TYPE_PEMERIKSAAN], true)) {
                $errors[] = "Baris {$lineNum}: tipe_paket '{$tipePaket}' tidak valid (BEDAH/PEMERIKSAAN), diabaikan";
                $tipePaket = '';
            }

            $key = mb_strtolower($namaPaket);
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'name'         => $namaPaket,
                    'category'     => trim((string) ($row['kategori'] ?? '')) ?: null,
                    'package_type' => $tipePaket ?: null,
                    'description'  => trim((string) ($row['deskripsi'] ?? '')) ?: null,
                    'is_active'    => $this->parseBool($row['aktif'] ?? '1'),
                    'items'        => [],
                    '_lines'       => [],
                ];
            }
            $grouped[$key]['_lines'][] = $lineNum;

            $itemTipe = strtoupper(trim((string) ($row['item_tipe'] ?? '')));
            $itemKat  = trim((string) ($row['item_kategori'] ?? ''));   // opsional: bantu disambiguasi nama
            $itemNama = trim((string) ($row['item_nama'] ?? ''));
            $qty      = (int) ($row['qty'] ?? 0);
            $harga    = $this->parseCsvPrice((string) ($row['harga'] ?? ''));   // opsional: null → ambil dari master

            // Baris tanpa item (paket kosong) — skip item, header tetap diproses
            if ($itemTipe === '' && $itemNama === '') {
                continue;
            }

            if (! in_array($itemTipe, SurgeryPackageItem::TYPES, true)) {
                $errors[] = "Baris {$lineNum}: item_tipe '{$itemTipe}' tidak valid (harus " . implode('/', SurgeryPackageItem::TYPES) . ')';
                continue;
            }
            if ($itemNama === '') {
                $errors[] = "Baris {$lineNum}: 'item_nama' kosong";
                continue;
            }
            if ($qty < 1) $qty = 1;

            $grouped[$key]['items'][] = [
                'item_type'     => $itemTipe,
                'item_category' => $itemKat ?: null,
                'item_name'     => $itemNama,
                'quantity'      => $qty,
                'default_price' => $harga,   // null → resolve dari master saat insert
                'notes'         => trim((string) ($row['catatan'] ?? '')) ?: null,
                '_line'         => $lineNum,
            ];
        }

        $created         = 0;
        $updated         = 0;
        $itemsInserted   = 0;
        $itemsLookupFail = 0;

        foreach ($grouped as $group) {
            $existing = SurgeryPackage::whereRaw('LOWER(name) = ?', [mb_strtolower($group['name'])])->first();

            DB::transaction(function () use ($group, $existing, &$created, &$updated, &$itemsInserted, &$itemsLookupFail, &$errors) {
                if ($existing) {
                    $existing->update([
                        'category'     => $group['category'] ?? $existing->category,
                        // package_type hanya disentuh bila CSV menyebut tipe (jaga tipe existing).
                        'package_type' => $group['package_type'] ?? $existing->package_type,
                        'description'  => $group['description'] ?? $existing->description,
                        'is_active'    => $group['is_active'],
                    ]);
                    // Replace items
                    $existing->items()->delete();
                    $pkg = $existing;
                    $updated++;
                } else {
                    $pkg = SurgeryPackage::create([
                        'name'             => $group['name'],
                        'category'         => $group['category'],
                        // Paket baru tanpa tipe → default BEDAH (boleh semua komponen).
                        'package_type'     => $group['package_type'] ?? SurgeryPackage::TYPE_BEDAH,
                        'description'      => $group['description'],
                        'is_active'        => $group['is_active'],
                        'total_base_price' => 0,
                    ]);
                    $created++;
                }

                foreach ($group['items'] as $itemRow) {
                    // Paket PEMERIKSAAN boleh PROCEDURE + MEDICATION (samakan dgn guard addItem).
                    if ($pkg->package_type === SurgeryPackage::TYPE_PEMERIKSAAN
                        && ! in_array($itemRow['item_type'], [SurgeryPackageItem::TYPE_PROCEDURE, SurgeryPackageItem::TYPE_MEDICATION], true)) {
                        $errors[] = "Baris {$itemRow['_line']}: paket PEMERIKSAAN hanya boleh Tindakan/Obat, item {$itemRow['item_type']} '{$itemRow['item_name']}' dilewati";
                        continue;
                    }

                    $itemId = $this->lookupItemIdByName($itemRow['item_type'], $itemRow['item_name'], $itemRow['item_category'] ?? null);
                    if (! $itemId) {
                        $itemsLookupFail++;
                        $errors[] = "Baris {$itemRow['_line']}: item {$itemRow['item_type']} '{$itemRow['item_name']}' tidak ditemukan di master";
                        continue;
                    }

                    // Harga dari CSV (bila diisi & > 0) menang; kosong → snapshot master Buku Tarif.
                    $defaultPrice = ($itemRow['default_price'] ?? null) > 0
                        ? (float) $itemRow['default_price']
                        : $this->resolveMasterPrice($itemRow['item_type'], $itemId);

                    SurgeryPackageItem::updateOrCreate(
                        [
                            'surgery_package_id' => $pkg->id,
                            'item_type'          => $itemRow['item_type'],
                            'item_id'            => $itemId,
                        ],
                        [
                            'quantity'      => $itemRow['quantity'],
                            'default_price' => $defaultPrice,
                            'notes'         => $itemRow['notes'],
                        ]
                    );
                    $itemsInserted++;
                }

                $pkg->recalcTotalBasePrice();
            });
        }

        $this->log(auth('api')->id(), 'IMPORT_PAKET_BEDAH_CSV', null, null,
            "new:{$created} upd:{$updated} items:{$itemsInserted} lookup_fail:{$itemsLookupFail}");

        return [
            'created'           => $created,
            'updated'           => $updated,
            'items_inserted'    => $itemsInserted,
            'items_lookup_fail' => $itemsLookupFail,
            'errors'            => $errors,
        ];
    }

    /** Wrapper publik lookupItemIdByName — dipakai command paket:import-excel. */
    public function lookupItemId(string $type, string $name, ?string $category = null): ?string
    {
        return $this->lookupItemIdByName($type, $name, $category);
    }

    /**
     * Replace SELURUH komposisi paket dengan item yang sudah ter-resolve (item_id pasti).
     * default_price null → snapshot harga Buku Tarif UMUM (resolveMasterPrice).
     * packageTariffs (harga jual per penjamin) TIDAK disentuh. Dipakai paket:import-excel.
     *
     * @param array<int, array{item_type:string,item_id:string,quantity:int,default_price?:float|null,notes?:string|null}> $items
     */
    public function replaceKomposisiResolved(SurgeryPackage $pkg, array $items): int
    {
        return DB::transaction(function () use ($pkg, $items) {
            $pkg->items()->delete();
            $n = 0;
            foreach ($items as $row) {
                SurgeryPackageItem::updateOrCreate(
                    [
                        'surgery_package_id' => $pkg->id,
                        'item_type'          => $row['item_type'],
                        'item_id'            => $row['item_id'],
                    ],
                    [
                        'quantity'      => max(1, (int) $row['quantity']),
                        'default_price' => ($row['default_price'] ?? null) > 0
                            ? (float) $row['default_price']
                            : $this->resolveMasterPrice($row['item_type'], $row['item_id']),
                        'notes'         => $row['notes'] ?? null,
                    ]
                );
                $n++;
            }
            $pkg->recalcTotalBasePrice();
            return $n;
        });
    }

    private function parseBool(mixed $v): bool
    {
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'yes', 'y', 'ya', 'aktif'], true);
    }

    /**
     * Pecah CSV jadi baris data: buang \r, baris kosong, dan baris komentar (#)
     * — petunjuk pengisian di template. Elemen pertama hasil = header.
     */
    private function csvDataLines(string $csvContent): array
    {
        $raw = explode("\n", str_replace("\r", '', trim($csvContent)));
        $lines = array_filter($raw, static function ($line) {
            $t = trim($line);
            return $t !== '' && ! str_starts_with($t, '#');
        });
        return array_values($lines);
    }

    /** Format item_nama untuk CSV (IOL pakai brand+model+power, lainnya pakai name). */
    private function formatItemNameForCsv(string $type, ?\Illuminate\Database\Eloquent\Model $resolved): string
    {
        if (! $resolved) return '';
        if ($type === SurgeryPackageItem::TYPE_IOL) {
            $brand = trim((string) ($resolved->brand ?? ''));
            $model = trim((string) ($resolved->model ?? ''));
            $power = $resolved->power !== null ? rtrim(rtrim(number_format((float) $resolved->power, 2, '.', ''), '0'), '.') . 'D' : '';
            return trim("{$brand} {$model} {$power}");
        }
        return (string) ($resolved->name ?? '');
    }

    /** Format harga snapshot komposisi untuk CSV: rupiah bulat tanpa pemisah/desimal. */
    private function formatPriceForCsv(mixed $price): string
    {
        return (string) (int) round((float) $price);
    }

    /**
     * Parse kolom harga CSV → float (rupiah). Toleran "Rp", spasi, dan pemisah ribuan
     * ("1.500.000" / "1500000"). Kosong / non-angka → null (caller fallback ke master).
     * Catatan: export menulis bilangan bulat tanpa desimal, jadi buang semua non-digit aman.
     */
    private function parseCsvPrice(string $raw): ?float
    {
        $digits = preg_replace('/[^\d]/', '', trim($raw));
        return ($digits === null || $digits === '') ? null : (float) $digits;
    }

    /** Lookup item ID by nama (case-insensitive). Return null kalau tidak ketemu atau ambigu. */
    private function lookupItemIdByName(string $type, string $name, ?string $category = null): ?string
    {
        $needle = mb_strtolower(trim($name));
        $cat    = ($category !== null && trim($category) !== '') ? mb_strtolower(trim($category)) : null;

        return match ($type) {
            SurgeryPackageItem::TYPE_PROCEDURE  => $this->lookupByNameCategory(Procedure::query(), $needle, $cat, 'category'),
            SurgeryPackageItem::TYPE_MEDICATION => $this->lookupByNameCategory(Medication::query(), $needle, $cat, 'golongan'),
            SurgeryPackageItem::TYPE_BHP        => $this->lookupByNameCategory(BhpItem::query(), $needle, $cat, 'category'),
            SurgeryPackageItem::TYPE_IOL        => $this->lookupIolByDisplayName($needle),
            default                             => null,
        };
    }

    /**
     * Cocokkan item by nama (case-insensitive). Bila >1 (ambigu) DAN item_kategori CSV diisi,
     * persempit pakai kolom kategori ($catCol = category/golongan; hardcoded, aman). Return id
     * hanya bila tepat 1 hasil. $catCol di-interpolasi tapi nilainya konstanta internal.
     */
    private function lookupByNameCategory(\Illuminate\Database\Eloquent\Builder $query, string $needle, ?string $cat, string $catCol): ?string
    {
        $base = (clone $query)->whereRaw('LOWER(name) = ?', [$needle]);
        $rows = (clone $base)->limit(2)->pluck('id');
        if ($rows->count() === 1) {
            return (string) $rows->first();
        }
        if ($cat !== null) {
            $narrow = (clone $base)->whereRaw("LOWER({$catCol}) = ?", [$cat])->limit(2)->pluck('id');
            if ($narrow->count() === 1) {
                return (string) $narrow->first();
            }
        }
        return null;
    }

    /**
     * IOL display name: "{brand} {model} {power}D" — power OPSIONAL.
     * Data katalog live banyak yang model/power NULL → export menulis nama tanpa
     * power; tanpa fallback ini, re-import gagal senyap (roundtrip putus).
     */
    private function lookupIolByDisplayName(string $needle): ?string
    {
        // 1. Cocokkan nama lengkap TANPA power: "brand" / "brand model" (tepat 1 baris).
        $exact = IolItem::whereRaw("LOWER(TRIM(CONCAT_WS(' ', brand, model))) = ?", [$needle])
            ->limit(2)->pluck('id');
        if ($exact->count() === 1) {
            return (string) $exact->first();
        }

        // 2. Tangkap power di akhir (mis. "21D" / "21.5D")
        if (! preg_match('/^(.*?)\s+([\d.]+)d\s*$/i', $needle, $m)) {
            return null;
        }
        $prefix = trim($m[1]);
        $power  = (float) $m[2];

        // prefix bisa "brand model" — coba match brand+model digabung
        $rows = IolItem::whereRaw('LOWER(CONCAT(brand, \' \', model)) = ?', [$prefix])
            ->whereRaw('ABS(power - ?) < 0.001', [$power])
            ->limit(2)
            ->pluck('id');

        return $rows->count() === 1 ? (string) $rows->first() : null;
    }

    // =========================================================================
    // INTERNAL
    // =========================================================================

    private function log(
        ?string $userId,
        string $action,
        ?string $model = null,
        ?string $modelId = null,
        ?string $description = null
    ): void {
        SystemLog::create([
            'user_id'     => $userId,
            'action'      => $action,
            'model'       => $model,
            'model_id'    => $modelId,
            'description' => $description,
            'ip_address'  => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }
}
