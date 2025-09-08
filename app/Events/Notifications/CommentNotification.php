<?php

namespace App\Events\Notifications;

use App\Models\Comment;
use App\Models\User;
use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Comment $comment,
        public User $recipient,
        public Notification $notification,
        public bool $isMention = false
    ) {
        // Load relationships
        $this->comment->load('task:id,title', 'user:id,name,avatar');
        $this->notification->load('user:id,name,avatar');
    }

    /**
     * Get the channels the event should broadcast on
     */
    public function broadcastOn(): Channel
    {
        return new Channel('user.' . $this->recipient->id);
    }

    /**
     * The event's broadcast name
     */
    public function broadcastAs(): string
    {
        return $this->isMention ? 'notification.mention' : 'notification.comment';
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->isMention ? 'mention' : 'comment',
            'timestamp' => now()->toISOString(),
            'notification' => [
                'id' => $this->notification->id,
                'title' => $this->notification->title,
                'message' => $this->notification->message,
                'type' => $this->notification->type,
                'data' => $this->notification->data,
                'created_at' => $this->notification->created_at,
                'icon' => $this->isMention ? '@' : '💬',
                'color' => $this->isMention ? '#fd7e14' : '#17a2b8',
                'priority' => $this->isMention ? 'medium' : 'low',
                'is_read' => false,
                'is_unread' => true,
            ],
            'comment' => [
                'id' => $this->comment->id,
                'content' => $this->comment->content,
                'task_title' => $this->comment->task->title,
            ],
            'commented_by' => [
                'id' => $this->comment->user->id,
                'name' => $this->comment->user->name,
                'avatar' => $this->comment->user->avatar,
            ]
        ];
    }
}
