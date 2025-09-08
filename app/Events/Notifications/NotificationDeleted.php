<?php

namespace App\Events\Notifications;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $notificationId,
        public int $userId,
        public string $title
    ) {
        //
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): Channel
    {
        return new Channel('user.' . $this->userId);
    }

    /**
     * The event's broadcast name
     */
    public function broadcastAs(): string
    {
        return 'notification.deleted';
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'action' => 'deleted',
            'timestamp' => now()->toISOString(),
            'notification_id' => $this->notificationId,
            'title' => $this->title,
        ];
    }
}
