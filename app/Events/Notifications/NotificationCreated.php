<?php

namespace App\Events\Notifications;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated implements ShouldBroadcast
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
        // Broadcast to the user who received the notification
        return new Channel('user.' . $this->notification->user_id);
    }

    /**
     * The event's broadcast name
     */
    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'action' => 'created',
            'timestamp' => now()->toISOString(),
            'notification' => [
                'id' => $this->notification->id,
                'title' => $this->notification->title,
                'message' => $this->notification->message,
                'type' => $this->notification->type,
                'data' => $this->notification->data,
                'created_at' => $this->notification->created_at,
                'read_at' => $this->notification->read_at,
                
                // Status helpers
                'is_read' => false, // New notifications are always unread
                'is_unread' => true,
                'age' => 'just now',
                
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
            ],
            // Include sender information if available
            'sender' => $this->notification->data['assigned_by_name'] ?? 
                       $this->notification->data['commented_by_name'] ?? 
                       $this->notification->data['mentioned_by_name'] ?? 
                       $this->notification->data['invited_by_name'] ?? 
                       'System'
        ];
    }
}
