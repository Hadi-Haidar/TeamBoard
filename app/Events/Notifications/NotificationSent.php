<?php

namespace App\Events\Notifications;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ?Notification $notification,
        public string $action,
        public array $data = []
    ) {
        // Load user relationship if notification exists
        if ($this->notification) {
            $this->notification->load('user:id,name,avatar');
        }
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): Channel
    {
        // Broadcast to the specific user's private channel
        $userId = $this->notification?->user_id ?? $this->data['user_id'] ?? null;
        return new Channel('user.' . $userId);
    }

    /**
     * The event's broadcast name
     */
    public function broadcastAs(): string
    {
        return 'notification.' . $this->action;
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        $baseData = [
            'action' => $this->action,
            'timestamp' => now()->toISOString(),
        ];

        // Include notification data if available
        if ($this->notification) {
            $baseData['notification'] = [
                'id' => $this->notification->id,
                'title' => $this->notification->title,
                'message' => $this->notification->message,
                'type' => $this->notification->type,
                'data' => $this->notification->data,
                'created_at' => $this->notification->created_at,
                'updated_at' => $this->notification->updated_at,
                'read_at' => $this->notification->read_at,
                
                // Status helpers
                'is_read' => $this->notification->isRead(),
                'is_unread' => $this->notification->isUnread(),
                'age' => $this->notification->age,
                
                // Display helpers
                'icon' => $this->notification->icon,
                'color' => $this->notification->color,
                'priority' => $this->notification->priority,
                
                // Type checks
                'is_task_assignment' => $this->notification->isTaskAssignment(),
                'is_comment' => $this->notification->isComment(),
                'is_mention' => $this->notification->isMention(),
                'is_board_invitation' => $this->notification->isBoardInvitation(),
                'is_due_reminder' => $this->notification->isDueReminder(),
                'is_task_completion' => $this->notification->isTaskCompletion(),
            ];
        }

        return array_merge($baseData, $this->data);
    }
}
