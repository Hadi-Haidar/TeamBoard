<?php

namespace App\Events\Comments;

use App\Models\Comment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Comment $comment)
    {
        $this->comment->load('user:id,name,avatar');
    }

    public function broadcastOn(): Channel
    {
        return new Channel('task.' . $this->comment->task_id);
    }

    public function broadcastAs(): string
    {
        return 'comment.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'comment' => [
                'id' => $this->comment->id,
                'content' => $this->comment->content,
                'created_at' => $this->comment->created_at,
                'updated_at' => $this->comment->updated_at,
                'task_id' => $this->comment->task_id,
                'user' => [
                    'id' => $this->comment->user->id,
                    'name' => $this->comment->user->name,
                    'avatar' => $this->comment->user->avatar,
                ],
                'created_at_human' => $this->comment->created_at->diffForHumans(),
                'updated_at_human' => $this->comment->updated_at->diffForHumans(),
                'is_edited' => true,
            ]
        ];
    }
}