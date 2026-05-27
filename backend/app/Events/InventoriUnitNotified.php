<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifikasi dari gudang Inventori Farmasi ke unit pemohon/peretur.
 * Dipancarkan saat admin memproses request/retur (approve/reject/deliver/receive),
 * sehingga view unit (mis. FarmasiView) bisa memunculkan toast realtime.
 *
 * Channel per-station: `inventori-farmasi-{STATION}` (mis. inventori-farmasi-FARMASI).
 */
class InventoriUnitNotified implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param string $station Stasiun unit tujuan (FARMASI, BEDAH, dll)
     * @param array  $payload { kind: 'request'|'return', action, number, status, message }
     */
    public function __construct(
        public readonly string $station,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel("inventori-farmasi-{$this->station}")];
    }

    public function broadcastAs(): string
    {
        return 'unit-notified';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
