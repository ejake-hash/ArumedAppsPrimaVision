<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast saat operator mengubah media yang ditampilkan di Antrean TV
 * (mode placeholder/youtube/localvideo/slideshow). TV subscribe channel
 * `antrean-tv` (sama dengan AntreanTvUpdated) dan reapply payload media.
 */
class TvMediaUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array       $media     payload media (bentuk AntreanTVView.applyMediaPayload)
     * @param string|null $deviceKey null = perubahan media GLOBAL (semua TV yang
     *                               synced menerapkannya); berisi key = perubahan
     *                               untuk SATU TV (hanya TV dengan device_key itu).
     */
    public function __construct(
        public readonly array $media,
        public readonly ?string $deviceKey = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('antrean-tv')];
    }

    public function broadcastAs(): string
    {
        return 'media-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'media'      => $this->media,
            'device_key' => $this->deviceKey,
        ];
    }
}
