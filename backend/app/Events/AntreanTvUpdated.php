<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic queue event untuk Antrean TV — fire untuk SEMUA station.
 * Channel: `antrean-tv` (public display, no auth).
 *
 * Channel station-specific (`admisi-queue`, `triase-queue`) tetap dipakai
 * oleh view per-station (AdmisiView, PerawatView, RefraksionisView).
 * Event ini paralel — TV dengar SATU channel saja untuk semua station.
 */
class AntreanTvUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly array $queueItem,
        public readonly string $action   // 'updated' | 'added'
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('antrean-tv')];
    }

    public function broadcastAs(): string
    {
        return 'queue-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'queue'  => $this->queueItem,
        ];
    }
}
