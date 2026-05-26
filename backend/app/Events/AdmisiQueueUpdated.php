<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdmisiQueueUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly array $queueItem,
        public readonly string $action   // 'updated' | 'added'
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('admisi-queue')];
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
