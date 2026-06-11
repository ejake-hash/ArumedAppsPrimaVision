<?php

namespace App\Console\Commands;

use App\Models\BhpItem;
use App\Models\Insurer;
use App\Models\Medication;
use App\Models\MedicationTariff;
use App\Models\Procedure;
use App\Models\ProcedureCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Samakan KODE item master dengan prefix kategori Buku Tarif (sheet Kategori):
 *   - Obat   → OBT-/OBP-/OBI- sesuai pos kwitansi tarif UMUM (tanpa tarif → OBP, selaras
 *              default pos OBAT_PULANG). Kode lama MED-xxx di-recode.
 *   - BHP    → kategori CSSD → CSSD-xxx, selainnya BHP-xxx (kode legacy BHPS-xxx di-recode).
 *   - Tindakan → prefix code_prefix kategorinya (umumnya sudah sesuai; recode bila melenceng).
 * IOL tanpa kolom kode (identitas = brand) — dilewati.
 *
 * Penomoran: per prefix, lanjut dari max suffix existing (withTrashed, anti-tabrakan
 * unique index), urut created_at agar stabil. Item yang sudah sesuai TIDAK disentuh.
 * Kode = tampilan/pencarian (relasi pakai UUID) — aman di-recode; mapping lama→baru
 * disimpan ke storage/app/ untuk arsip.
 *
 * Default DRY-RUN (transaksi + rollback). --apply untuk menyimpan.
 */
class SamakanKodeBukuTarif extends Command
{
    protected $signature = 'master:samakan-kode-buku-tarif {--apply : Simpan perubahan (default dry-run)}';

    protected $description = 'Recode kode item obat/BHP/tindakan mengikuti prefix kategori Buku Tarif (OBT/OBP/OBI, CSSD/BHP, prefix kategori tindakan)';

    private array $counters = [];   // prefix => suffix terakhir terpakai
    private array $mapping  = [];   // [tipe, nama, kode lama, kode baru]

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $umumId = Insurer::where('is_system', true)->where('type', 'UMUM')->value('id');
        if (! $umumId) {
            $this->error('Penjamin sistem UMUM tidak ditemukan.');
            return self::FAILURE;
        }

        DB::beginTransaction();
        try {
            // ── Obat: prefix per pos kwitansi tarif UMUM ────────────────────
            $posMap = DB::table('medication_tariffs')
                ->where('insurer_id', $umumId)->where('is_active', true)
                ->pluck('pos_kwitansi', 'medication_id');
            foreach (Medication::orderBy('created_at')->get(['id', 'code', 'name']) as $m) {
                $prefix = MedicationTariff::posCodePrefix($posMap[$m->id] ?? null);
                $this->recode(Medication::class, 'OBAT', $m, $prefix);
            }

            // ── BHP: CSSD → CSSD-, lainnya → BHP- ───────────────────────────
            foreach (BhpItem::orderBy('created_at')->get(['id', 'code', 'name', 'category']) as $b) {
                $prefix = $b->category === BhpItem::CATEGORY_CSSD ? 'CSSD' : 'BHP';
                $this->recode(BhpItem::class, 'BHP', $b, $prefix);
            }

            // ── Tindakan: prefix dari kategori (skip kategori tak terdaftar) ─
            $catPrefix = ProcedureCategory::pluck('code_prefix', 'name');
            foreach (Procedure::orderBy('created_at')->get(['id', 'code', 'name', 'category']) as $p) {
                $prefix = $catPrefix[$p->category] ?? null;
                if ($prefix) {
                    $this->recode(Procedure::class, 'TINDAKAN', $p, $prefix);
                }
            }

            $this->report();

            if ($apply) {
                DB::commit();
                $path = $this->saveMapping();
                $this->info('APPLY: ' . count($this->mapping) . ' kode di-recode. Mapping: ' . ($path ?? '-'));
            } else {
                DB::rollBack();
                $this->warn('DRY-RUN: tidak ada perubahan tersimpan (' . count($this->mapping) . ' kandidat recode). Jalankan ulang dengan --apply.');
            }
            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('ERROR: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function recode(string $modelClass, string $label, $item, string $prefix): void
    {
        if (str_starts_with((string) $item->code, $prefix . '-')) {
            return;   // sudah sesuai
        }
        $next = $this->nextSuffix($modelClass, $prefix);
        $new  = sprintf('%s-%03d', $prefix, $next);
        $this->mapping[] = [$label, $item->name, $item->code, $new];
        $item->update(['code' => $new]);
    }

    /** Suffix berikutnya per prefix — seed dari max existing withTrashed (unique index incl. trashed). */
    private function nextSuffix(string $modelClass, string $prefix): int
    {
        $key = $modelClass . '|' . $prefix;
        if (! isset($this->counters[$key])) {
            $this->counters[$key] = $modelClass::withTrashed()
                ->where('code', 'like', $prefix . '-%')
                ->get(['code'])
                ->map(function ($r) use ($prefix) {
                    $suffix = substr((string) $r->code, strlen($prefix) + 1);
                    return ctype_digit($suffix) ? (int) $suffix : 0;
                })
                ->max() ?? 0;
        }
        return ++$this->counters[$key];
    }

    private function report(): void
    {
        $perTipe = [];
        foreach ($this->mapping as [$tipe]) {
            $perTipe[$tipe] = ($perTipe[$tipe] ?? 0) + 1;
        }
        foreach ($perTipe as $tipe => $n) {
            $this->info("{$tipe}: {$n} item di-recode");
        }
        foreach (array_slice($this->mapping, 0, 15) as [$tipe, $nama, $lama, $baru]) {
            $this->line(sprintf('  %-9s %-45s %-12s -> %s', $tipe, mb_substr($nama, 0, 45), $lama, $baru));
        }
        if (count($this->mapping) > 15) {
            $this->line('  ... (' . (count($this->mapping) - 15) . ' lainnya; mapping lengkap disimpan saat --apply)');
        }
    }

    private function saveMapping(): ?string
    {
        if (! $this->mapping) {
            return null;
        }
        $path = storage_path('app/recode_kode_buku_tarif_' . now()->format('Ymd_His') . '.csv');
        $fh = fopen($path, 'w');
        fputcsv($fh, ['tipe', 'nama', 'kode_lama', 'kode_baru'], ',', '"', '\\');
        foreach ($this->mapping as $row) {
            fputcsv($fh, $row, ',', '"', '\\');
        }
        fclose($fh);
        return $path;
    }
}
