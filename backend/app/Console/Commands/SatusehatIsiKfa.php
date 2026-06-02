<?php

namespace App\Console\Commands;

use App\Models\Medication;
use App\Services\SatusehatService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Isi medications.kfa_code massal via pencarian KFA Satu Sehat.
 *
 * Latar: peresepan TIDAK terkirim ke Satu Sehat bila obat tak punya kfa_code —
 * SatusehatService::buildMedicationEntries() men-skip item tanpa KFA. Lihat
 * memory project-satusehat-kfa-resep-gap.
 *
 * Strategi match AMAN (default): hanya auto-isi bila pencarian KFA mengembalikan
 * TEPAT 1 hasil, ATAU ada hasil dengan nama PERSIS (case-insensitive) dengan
 * obat. Hasil ambigu / nihil DILEWATI & dicatat ke laporan untuk ditinjau manual
 * (mencegah salah kode → data klinis salah ke Satu Sehat).
 */
class SatusehatIsiKfa extends Command
{
    protected $signature = 'satusehat:isi-kfa
                            {--all : Proses SEMUA obat aktif tanpa KFA (default: hanya yg pernah diresepkan)}
                            {--apply : Tulis kfa_code ke DB (default: dry-run, tidak menulis apa pun)}
                            {--first : Pakai hasil pertama saat ambigu (TIDAK disarankan, bisa salah kode)}
                            {--limit=0 : Batasi jumlah obat yang diproses (0 = tanpa batas)}';

    protected $description = 'Isi kfa_code master obat dari KFA Satu Sehat (match aman: tunggal/nama-persis). Default dry-run.';

    public function handle(SatusehatService $satusehat): int
    {
        $apply = (bool) $this->option('apply');
        $useFirst = (bool) $this->option('first');
        $limit = (int) $this->option('limit');

        $medications = $this->targetMedications((bool) $this->option('all'), $limit);

        if ($medications->isEmpty()) {
            $this->info('Tidak ada obat tanpa kfa_code untuk diproses.');
            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%s %d obat tanpa KFA%s.',
            $apply ? 'MENGISI' : '[DRY-RUN] Memindai',
            $medications->count(),
            $useFirst ? ' (mode --first: pakai hasil teratas)' : ''
        ));
        $this->newLine();

        $filled = 0;
        $ambiguous = [];
        $notFound = [];
        $errors = [];

        foreach ($medications as $med) {
            $keyword = trim((string) ($med->generic_name ?: $med->name));
            if ($keyword === '') {
                $notFound[] = [$med->code, $med->name, 'nama & generic_name kosong'];
                continue;
            }

            $res = $satusehat->searchKfa($keyword);
            if (! ($res['success'] ?? false)) {
                $errors[] = [$med->code, $med->name, $res['message'] ?? 'pencarian KFA gagal'];
                // Hentikan dini bila integrasi mati / auth gagal — semua sisanya akan gagal sama.
                if (Str::contains(strtolower($res['message'] ?? ''), ['belum diaktifkan', 'autentikasi', 'client_id'])) {
                    $this->error("Integrasi Satu Sehat bermasalah: {$res['message']}");
                    $this->error('Hentikan — aktifkan & lengkapi credential Satu Sehat dulu.');
                    return self::FAILURE;
                }
                continue;
            }

            $items = $res['items'] ?? [];
            $chosen = $this->pickKfa($items, $med->name, $useFirst);

            if (! $chosen) {
                if (empty($items)) {
                    $notFound[] = [$med->code, $med->name, "0 hasil utk '{$keyword}'"];
                } else {
                    $ambiguous[] = [$med->code, $med->name, count($items) . ' kandidat — pilih manual'];
                }
                continue;
            }

            $this->line(sprintf(
                '  %-12s %-34s → %s  %s',
                $med->code ?? '-',
                Str::limit($med->name, 32),
                $chosen['kfa_code'],
                Str::limit($chosen['name'] ?? '', 30)
            ));

            if ($apply) {
                $med->kfa_code = $chosen['kfa_code'];
                $med->save();
            }
            $filled++;
        }

        $this->renderSummary($apply, $filled, $ambiguous, $notFound, $errors);

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int,Medication> */
    private function targetMedications(bool $all, int $limit)
    {
        $q = Medication::query()
            ->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('kfa_code')->orWhere('kfa_code', ''));

        if (! $all) {
            // Hanya obat yang pernah diresepkan (yang benar-benar dikirim ke Satu Sehat).
            $q->whereIn('id', function ($sub) {
                $sub->select('medication_id')->distinct()->from('prescription_items')->whereNotNull('medication_id');
            });
        }

        $q->orderBy('name');
        if ($limit > 0) {
            $q->limit($limit);
        }

        return $q->get();
    }

    /**
     * Pilih KFA dengan aturan AMAN: nama persis dulu, lalu hasil tunggal.
     * Dengan --first, pakai kandidat pertama yang punya kfa_code.
     */
    private function pickKfa(array $items, string $medName, bool $useFirst): ?array
    {
        $valid = array_values(array_filter($items, fn ($i) => ! empty($i['kfa_code'])));
        if (empty($valid)) {
            return null;
        }

        // 1. Nama PERSIS (case-insensitive) — paling tepat.
        $needle = $this->normalize($medName);
        foreach ($valid as $i) {
            if ($this->normalize((string) ($i['name'] ?? '')) === $needle) {
                return $i;
            }
        }

        // 2. Hasil tunggal — aman dipakai.
        if (count($valid) === 1) {
            return $valid[0];
        }

        // 3. Ambigu — pakai pertama HANYA bila diminta eksplisit.
        return $useFirst ? $valid[0] : null;
    }

    private function normalize(string $s): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($s)));
    }

    private function renderSummary(bool $apply, int $filled, array $ambiguous, array $notFound, array $errors): void
    {
        $this->newLine();
        $verb = $apply ? 'Diisi' : 'Cocok (dry-run, belum ditulis)';
        $this->info("{$verb}: {$filled} obat.");

        if ($ambiguous) {
            $this->newLine();
            $this->warn('Ambigu (perlu pilih manual via UI master Obat → Cari KFA): ' . count($ambiguous));
            $this->table(['Kode', 'Nama', 'Catatan'], $ambiguous);
        }
        if ($notFound) {
            $this->newLine();
            $this->warn('Tidak ditemukan di KFA: ' . count($notFound));
            $this->table(['Kode', 'Nama', 'Catatan'], $notFound);
        }
        if ($errors) {
            $this->newLine();
            $this->error('Error: ' . count($errors));
            $this->table(['Kode', 'Nama', 'Pesan'], $errors);
        }

        if (! $apply && $filled > 0) {
            $this->newLine();
            $this->comment('Ini DRY-RUN. Jalankan ulang dengan --apply untuk menulis ke DB.');
        }
    }
}
