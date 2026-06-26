<?php

namespace App\Jobs;

use App\Models\Room;
use App\Services\BpjsAplicareService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push ketersediaan satu ruang ke BPJS Aplicare setelah okupansi bed berubah
 * (admit / transfer / discharge / bed siap kembali). Non-blocking: dijalankan
 * async dari RanapService; kegagalan TIDAK memblok flow rawat inap lokal —
 * jaring rekonsiliasi tetap dijalankan command aplicare:sync.
 *
 * Guard: no-op bila integrasi APLICARE belum aktif, ruang tak ditemukan, atau
 * ruang belum dipetakan kode kelas BPJS (Aplicare butuh kodekelas valid).
 */
class PushAplicareRoom implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $roomId) {}

    public function handle(BpjsAplicareService $aplicare): void
    {
        if (! $aplicare->isEnabled()) {
            return;
        }

        $room = Room::with('activeBeds')->find($this->roomId);
        if (! $room || empty($room->bpjs_kelas_code)) {
            return;
        }

        try {
            $aplicare->pushRoom($room);
        } catch (\Throwable $e) {
            // Tetap non-blocking; tercatat di log aplikasi + bpjs_aplicare_logs.
            Log::warning('PushAplicareRoom gagal: ' . $e->getMessage(), ['room_id' => $this->roomId]);
        }
    }
}
