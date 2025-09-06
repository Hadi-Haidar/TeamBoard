<?php

namespace App\Events\Attachments;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AttachmentDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $taskId,
        public int $attachmentId
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('task.' . $this->taskId);
    }

    public function broadcastAs(): string
    {
        return 'attachment.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'attachment_id' => $this->attachmentId,
            'task_id' => $this->taskId,
        ];
    }
}
