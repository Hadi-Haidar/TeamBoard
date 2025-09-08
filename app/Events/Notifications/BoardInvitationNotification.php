<?php

namespace App\Events\Notifications;

use App\Models\BoardMember;
use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BoardInvitationNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public BoardMember $invitation,
        public Notification $notification
    ) {
        // Load relationships
        $this->invitation->load('board:id,title,description', 'inviter:id,name,avatar');
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
        return 'notification.board_invitation';
    }

    /**
     * Get the data to broadcast
     */
    public function broadcastWith(): array
    {
        return [
            'action' => 'board_invitation',
            'timestamp' => now()->toISOString(),
            'notification' => [
                'id' => $this->notification->id,
                'title' => $this->notification->title,
                'message' => $this->notification->message,
                'type' => $this->notification->type,
                'data' => $this->notification->data,
                'created_at' => $this->notification->created_at,
                'icon' => '📧',
                'color' => '#007bff',
                'priority' => 'medium',
                'is_read' => false,
                'is_unread' => true,
            ],
            'board' => [
                'id' => $this->invitation->board->id,
                'title' => $this->invitation->board->title,
                'description' => $this->invitation->board->description,
            ],
            'invited_by' => [
                'id' => $this->invitation->inviter->id,
                'name' => $this->invitation->inviter->name,
                'avatar' => $this->invitation->inviter->avatar,
            ],
            'role' => $this->invitation->role,
            'invitation_token' => $this->invitation->invitation_token,
        ];
    }
}
