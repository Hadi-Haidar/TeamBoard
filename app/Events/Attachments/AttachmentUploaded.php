<?php

namespace App\Events\Attachments;

use App\Models\Attachment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttachmentUploaded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Attachment $attachment)
    {
        $this->attachment->load('user:id,name,avatar');
    }

    public function broadcastOn(): Channel
    {
        return new Channel('task.' . $this->attachment->task_id);
    }

    public function broadcastAs(): string
    {
        return 'attachment.uploaded';
    }

    public function broadcastWith(): array
    {
        return [
            'attachment' => [
                'id' => $this->attachment->id,
                'file_name' => $this->attachment->file_name,
                'file_size' => $this->attachment->file_size,
                'mime_type' => $this->attachment->mime_type,
                'task_id' => $this->attachment->task_id,
                'created_at' => $this->attachment->created_at,
                'uploaded_by' => [
                    'id' => $this->attachment->user->id,
                    'name' => $this->attachment->user->name,
                    'avatar' => $this->attachment->user->avatar,
                ],
            ]
        ];
    }
}
