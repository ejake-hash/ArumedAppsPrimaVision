<?php

namespace App\Observers;

use App\Models\DiagnosticTestType;
use App\Models\Procedure;

/**
 * Mirror procedures kategori "Penunjang" → diagnostic_test_types (kode sama).
 *
 * Arsitektur: master tunggal penunjang = procedures kategori "Penunjang"
 * (dikelola di Tarif Tindakan, ada harga). `diagnostic_test_types` dipertahankan
 * sebagai turunan (dipakai alur order/hasil/biometri via diagnostic_orders),
 * di-sinkron otomatis dari procedures di sini. Harga TIDAK ikut (tarif via
 * procedure_tariffs). Biometri ditandai kode DiagnosticTestType::BIOMETRI_CODE.
 */
class ProcedureObserver
{
    public const CATEGORY = 'Penunjang';

    public function saved(Procedure $procedure): void
    {
        if ($procedure->category === self::CATEGORY) {
            // Procedure penunjang → buat/segarkan baris cermin.
            DiagnosticTestType::withTrashed()->updateOrCreate(
                ['code' => $procedure->code],
                [
                    'name'      => $procedure->name,
                    'category'  => $procedure->category,
                    'is_active' => (bool) $procedure->is_active,
                    'deleted_at' => null,
                ],
            );
        } else {
            // Kategori bukan/lagi bukan Penunjang → nonaktifkan cermin bila ada.
            $this->deactivateMirror($procedure->code);
        }
    }

    public function deleted(Procedure $procedure): void
    {
        $this->deactivateMirror($procedure->code);
    }

    public function restored(Procedure $procedure): void
    {
        if ($procedure->category === self::CATEGORY) {
            DiagnosticTestType::withTrashed()->updateOrCreate(
                ['code' => $procedure->code],
                [
                    'name'       => $procedure->name,
                    'category'   => $procedure->category,
                    'is_active'  => (bool) $procedure->is_active,
                    'deleted_at' => null,
                ],
            );
        }
    }

    private function deactivateMirror(string $code): void
    {
        $mirror = DiagnosticTestType::where('code', $code)->first();
        if ($mirror) {
            // Soft-delete cermin agar tidak muncul di daftar jenis penunjang,
            // tapi order/hasil lama yang sudah menyimpan kode tetap bisa di-resolve.
            $mirror->update(['is_active' => false]);
            $mirror->delete();
        }
    }
}
