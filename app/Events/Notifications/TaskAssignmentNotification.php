<?php

namespace App\Events\Notifications;

use App\Models\Task;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskAssignmentNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Task $task,
        public User $assignedUser,
        public User $assignedBy,
        public Notification $notification
    ) {
        // Load relationships
        $this->task->load('list.board:id,title');
        $this->notification->load('user:id,name,avatar');
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): Channel
    {
        return new Channel('user.' . $this->assignedUser->id);
    }

    /**
     * The event's broadcast name
     */
    public function broadcastAs(): string
    {
        return 'notification.task_assigned';
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'action' => 'task_assigned',
            'timestamp' => now()->toISOString(),
            'notification' => [
                'id' => $this->notification->id,
                'title' => $this->notification->title,
                'message' => $this->notification->message,
                'type' => $this->notification->type,
                'data' => $this->notification->data,
                'created_at' => $this->notification->created_at,
                'icon' => '👤',
                'color' => '#6f42c1',
                'priority' => 'medium',
                'is_read' => false,
                'is_unread' => true,
            ],
            'task' => [
                'id' => $this->task->id,
                'title' => $this->task->title,
                'description' => $this->task->description,
                'board_title' => $this->task->list->board->title,
            ],
            'assigned_by' => [
                'id' => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
                'avatar' => $this->assignedBy->avatar,
            ]
        ];
    }
}
