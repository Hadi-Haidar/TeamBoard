<?php

namespace App\Events\Notifications;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Notification $notification
    ) {
        // Load user relationship
        $this->notification->load('user:id,name,avatar');
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): Channel
    {
        return new Channel('user.' . $this->notification->user_id);
    }

    /**
     * The event's broadcast name
     */
    public function broadcastAs(): string
    {
        return 'notification.read';
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'action' => 'read',
            'timestamp' => now()->toISOString(),
            'notification_id' => $this->notification->id,
            'read_at' => $this->notification->read_at,
            'is_read' => true,
            'is_unread' => false,
        ];
    }
}
