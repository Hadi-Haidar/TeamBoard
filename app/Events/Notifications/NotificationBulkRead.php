<?php

namespace App\Events\Notifications;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationBulkRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $user,
        public int $updatedCount
    ) {
        //
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): Channel
    {
        return new Channel('user.' . $this->user->id);
    }

    /**
     * The event's broadcast name
     */
    public function broadcastAs(): string
    {
        return 'notification.bulk_read';
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'action' => 'bulk_read',
            'timestamp' => now()->toISOString(),
            'user_id' => $this->user->id,
            'updated_count' => $this->updatedCount,
            'message' => "Marked {$this->updatedCount} notifications as read",
        ];
    }
}
