<?php

namespace App\Console\Commands;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remediasi data: pulihkan ATURAN PAKAI resep yang hilang akibat bug lama
 * "Buka Kembali → simpan ulang" (delete+recreate melebur item TAMBAHAN Farmasi
 * jadi item RESEP polos tanpa dosage/instructions/dose/frequency).
 *
 * Sumber pemulihan = baris item LAMA yang ter-soft-delete (REPLACE memakai
 * SoftDeletes, jadi aturan pakai aslinya masih ada di DB). Item hidup yang
 * aturan-nya kosong dicocokkan ke item terhapus se-VISIT dengan medication_id
 * sama, lalu field aturan + audit TAMBAHAN disalin balik (hanya mengisi yang
 * kosong — tidak menimpa isian baru).
 *
 * Aman dijalankan berulang (idempoten): item yang sudah punya aturan dilewati.
 * Default DRY-RUN; tulis perubahan hanya dengan --apply.
 *
 *   php artisan resep:pulihkan-aturan                  (dry-run, 7 hari terakhir)
 *   php artisan resep:pulihkan-aturan --days=14
 *   php artisan resep:pulihkan-aturan --visit=<uuid>
 *   php artisan resep:pulihkan-aturan --apply
 */
class PulihkanAturanResep extends Command
{
    protected $signature = 'resep:pulihkan-aturan
        {--visit= : Batasi ke satu visit (uuid)}
        {--days=7 : Pindai resep yang dibuat N hari terakhir}
        {--apply : Tulis perubahan (tanpa ini = dry-run)}';

    protected $description = 'Pulihkan aturan pakai resep yang hilang akibat replace "Buka Kembali" (salin balik dari item soft-deleted)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $visit = $this->option('visit');
        $days  = max(1, (int) $this->option('days'));

        // Kandidat: item HIDUP tanpa aturan sama sekali, pada resep aktif (bukan
        // CANCELLED) non-RANAP. Kosong = keempat field aturan null/'' sekaligus.
        $kandidat = PrescriptionItem::query()
            ->whereHas('prescription', function ($q) use ($visit, $days) {
                $q->where('status', '!=', 'CANCELLED')
                  ->where('type', '!=', Prescription::TYPE_RANAP)
                  ->when($visit, fn ($w) => $w->where('visit_id', $visit))
                  ->when(! $visit, fn ($w) => $w->where('created_at', '>=', now()->subDays($days)));
            })
            ->where(function ($q) {
                foreach (['dose', 'dosage', 'frequency', 'instructions'] as $f) {
                    $q->where(fn ($w) => $w->whereNull($f)->orWhere($f, ''));
                }
            })
            ->with(['prescription:id,visit_id', 'medication:id,name'])
            ->get();

        if ($kandidat->isEmpty()) {
            $this->info('Tidak ada item resep tanpa aturan pakai pada rentang ini.');
            return self::SUCCESS;
        }

        $dipulihkan = 0;
        $tanpaSumber = 0;

        foreach ($kandidat as $item) {
            $visitId = $item->prescription?->visit_id;
            if (! $visitId) {
                continue;
            }

            // Sumber: item ter-soft-delete se-visit dgn obat sama yang MASIH punya
            // aturan; ambil yang paling akhir dihapus (jejak terdekat sebelum bug).
            $sumber = PrescriptionItem::onlyTrashed()
                ->where('medication_id', $item->medication_id)
                ->whereIn('prescription_id', Prescription::withTrashed()
                    ->where('visit_id', $visitId)->pluck('id'))
                ->where(function ($q) {
                    $q->whereNotNull('dose')->orWhereNotNull('dosage')
                      ->orWhereNotNull('frequency')->orWhereNotNull('instructions');
                })
                ->orderByDesc('deleted_at')
                ->first();

            if (! $sumber) {
                $tanpaSumber++;
                $this->line(sprintf(
                    '  SKIP  %s — visit %s: tidak ditemukan jejak item lama ber-aturan',
                    $item->medication?->name ?? $item->medication_id, $visitId
                ));
                continue;
            }

            // Salin HANYA field yang kosong di item hidup (jangan timpa isian baru).
            $patch = [];
            foreach (['dose', 'dosage', 'frequency', 'instructions', 'route', 'duration_days'] as $f) {
                if (($item->{$f} === null || $item->{$f} === '') && $sumber->{$f} !== null && $sumber->{$f} !== '') {
                    $patch[$f] = $sumber->{$f};
                }
            }
            // Pulihkan identitas TAMBAHAN + audit bila item lama memang TAMBAHAN
            // dan item hidup sudah ter-flip jadi RESEP polos.
            if (($sumber->source ?? null) === 'TAMBAHAN' && ($item->source ?? 'RESEP') !== 'TAMBAHAN') {
                $patch['source'] = 'TAMBAHAN';
                foreach (['added_by_id', 'change_reason', 'changed_by_id', 'changed_at', 'original_medication_id'] as $f) {
                    if ($item->{$f} === null && $sumber->{$f} !== null) {
                        $patch[$f] = $sumber->{$f};
                    }
                }
            }

            if (! $patch) {
                $tanpaSumber++;
                continue;
            }

            $this->line(sprintf(
                '  %s %s — visit %s: %s',
                $apply ? 'FIX ' : 'PLAN',
                $item->medication?->name ?? $item->medication_id,
                $visitId,
                json_encode($patch, JSON_UNESCAPED_UNICODE)
            ));

            if ($apply) {
                DB::transaction(function () use ($item, $patch) {
                    // Lewat fill+save per-atribut (sebagian kolom tak fillable —
                    // set langsung, pola PerawatService::storePreopPrescription).
                    foreach ($patch as $f => $v) {
                        $item->{$f} = $v;
                    }
                    $item->save();
                });
            }
            $dipulihkan++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d item %s, %d dilewati (tanpa jejak). %s',
            $apply ? 'SELESAI' : 'DRY-RUN',
            $dipulihkan,
            $apply ? 'dipulihkan' : 'akan dipulihkan',
            $tanpaSumber,
            $apply ? '' : 'Jalankan ulang dengan --apply untuk menulis.'
        ));

        return self::SUCCESS;
    }
}
