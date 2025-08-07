<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'type',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==== RELATIONSHIPS ====

    /**
     * The user this notification belongs to
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ==== STATUS HELPERS ====

    /**
     * Check if notification is read
     */
    public function isRead(): bool
    {
        return !is_null($this->read_at);
    }

    /**
     * Check if notification is unread
     */
    public function isUnread(): bool
    {
        return is_null($this->read_at);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(): bool
    {
        if ($this->isRead()) {
            return true; // Already read
        }

        return $this->update(['read_at' => now()]);
    }

    /**
     * Mark notification as unread
     */
    public function markAsUnread(): bool
    {
        return $this->update(['read_at' => null]);
    }

    // ==== NOTIFICATION TYPES ====

    /**
     * Check if this is a task assignment notification
     */
    public function isTaskAssignment(): bool
    {
        return $this->type === 'task_assigned';
    }

    /**
     * Check if this is a comment notification
     */
    public function isComment(): bool
    {
        return in_array($this->type, ['task_comment', 'comment_reply']);
    }

    /**
     * Check if this is a mention notification
     */
    public function isMention(): bool
    {
        return $this->type === 'mention';
    }

    /**
     * Check if this is a board invitation
     */
    public function isBoardInvitation(): bool
    {
        return $this->type === 'board_invitation';
    }

    /**
     * Check if this is a due date reminder
     */
    public function isDueReminder(): bool
    {
        return in_array($this->type, ['due_reminder', 'overdue_reminder']);
    }

    /**
     * Check if this is a task completion notification
     */
    public function isTaskCompletion(): bool
    {
        return $this->type === 'task_completed';
    }

    // ==== NOTIFICATION DISPLAY ====

    /**
     * Get notification icon based on type
     */
    public function getIconAttribute(): string
    {
        return match($this->type) {
            'task_assigned' => '👤',
            'task_completed' => '✅',
            'task_comment' => '💬',
            'comment_reply' => '↩️',
            'mention' => '@',
            'board_invitation' => '📧',
            'board_shared' => '🔗',
            'due_reminder' => '⏰',
            'overdue_reminder' => '🚨',
            'deadline_missed' => '❌',
            'file_uploaded' => '📎',
            'board_activity' => '📊',
            'system_update' => '🔄',
            default => '🔔'
        };
    }

    /**
     * Get notification color based on type
     */
    public function getColorAttribute(): string
    {
        return match($this->type) {
            'task_assigned' => '#6f42c1',      // Purple
            'task_completed' => '#28a745',     // Green
            'task_comment', 'comment_reply' => '#17a2b8', // Blue
            'mention' => '#fd7e14',            // Orange
            'board_invitation' => '#007bff',   // Blue
            'due_reminder' => '#ffc107',       // Yellow
            'overdue_reminder', 'deadline_missed' => '#dc3545', // Red
            'file_uploaded' => '#6c757d',      // Gray
            default => '#6c757d'               // Gray
        };
    }

    /**
     * Get notification priority level
     */
    public function getPriorityAttribute(): string
    {
        return match($this->type) {
            'overdue_reminder', 'deadline_missed' => 'high',
            'board_invitation', 'mention', 'task_assigned' => 'medium',
            default => 'low'
        };
    }

    /**
     * Get time since notification was created
     */
    public function getAgeAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Get formatted notification content
     */
    public function getFormattedMessageAttribute(): string
    {
        $message = $this->message;
        
        // Add links based on notification data
        if ($this->data) {
            // Task links
            if (isset($this->data['task_id'])) {
                $taskUrl = route('tasks.show', $this->data['task_id']);
                $message = preg_replace(
                    '/task [\'"]([^\'"]+)[\'"]/i',
                    "task <a href='{$taskUrl}' class='notification-link'>'\$1'</a>",
                    $message
                );
            }

            // Board links
            if (isset($this->data['board_id'])) {
                $boardUrl = route('boards.show', $this->data['board_id']);
                $message = preg_replace(
                    '/board [\'"]([^\'"]+)[\'"]/i',
                    "board <a href='{$boardUrl}' class='notification-link'>'\$1'</a>",
                    $message
                );
            }
        }

        return $message;
    }

    /**
     * Get action URL for this notification
     */
    public function getActionUrlAttribute(): ?string
    {
        if (!$this->data) {
            return null;
        }

        return match($this->type) {
            'task_assigned', 'task_completed', 'task_comment', 'due_reminder' => 
                isset($this->data['task_id']) ? route('tasks.show', $this->data['task_id']) : null,
            
            'board_invitation', 'board_shared' => 
                isset($this->data['board_id']) ? route('boards.show', $this->data['board_id']) : null,
            
            'mention' => 
                isset($this->data['comment_id']) ? route('comments.show', $this->data['comment_id']) : null,
            
            default => null
        };
    }

    /**
     * Get action text for this notification
     */
    public function getActionTextAttribute(): string
    {
        return match($this->type) {
            'task_assigned', 'task_completed', 'task_comment' => 'View Task',
            'board_invitation' => 'View Invitation',
            'board_shared' => 'View Board',
            'mention' => 'View Comment',
            'due_reminder' => 'View Task',
            default => 'View'
        };
    }

    // ==== NOTIFICATION ACTIONS ====

    /**
     * Accept board invitation (if applicable)
     */
    public function acceptInvitation(): bool
    {
        if (!$this->isBoardInvitation() || !isset($this->data['invitation_token'])) {
            return false;
        }

        $invitation = BoardMember::findByToken($this->data['invitation_token']);
        
        if ($invitation && $invitation->accept()) {
            $this->markAsRead();
            return true;
        }

        return false;
    }

    /**
     * Decline board invitation (if applicable)
     */
    public function declineInvitation(): bool
    {
        if (!$this->isBoardInvitation() || !isset($this->data['invitation_token'])) {
            return false;
        }

        $invitation = BoardMember::findByToken($this->data['invitation_token']);
        
        if ($invitation && $invitation->decline()) {
            $this->markAsRead();
            return true;
        }

        return false;
    }

    /**
     * Snooze notification for X minutes
     */
    public function snooze(int $minutes = 60): bool
    {
        // Create a new notification for later
        return self::create([
            'user_id' => $this->user_id,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'data' => $this->data,
            'created_at' => now()->addMinutes($minutes),
        ]) && $this->delete();
    }

    // ==== SCOPES ====

    /**
     * Scope to get unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope to get read notifications
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope to get notifications by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to get notifications for a specific user
     */
    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Scope to get recent notifications
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to get notifications in chronological order
     */
    public function scopeChronological($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope to get high priority notifications
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('type', [
            'overdue_reminder',
            'deadline_missed',
            'board_invitation'
        ]);
    }

    /**
     * Scope to get task-related notifications
     */
    public function scopeTaskRelated($query)
    {
        return $query->whereIn('type', [
            'task_assigned',
            'task_completed',
            'task_comment',
            'due_reminder',
            'overdue_reminder'
        ]);
    }

    /**
     * Scope to get board-related notifications
     */
    public function scopeBoardRelated($query)
    {
        return $query->whereIn('type', [
            'board_invitation',
            'board_shared',
            'board_activity'
        ]);
    }

    /**
     * Scope to search notifications
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
              ->orWhere('message', 'like', "%{$search}%");
        });
    }

    // ==== STATIC METHODS ====

    /**
     * Create task assignment notification
     */
    public static function createTaskAssignment(Task $task, User $assignedUser, User $assignedBy): self
    {
        return self::create([
            'user_id' => $assignedUser->id,
            'title' => 'New Task Assigned',
            'message' => "You have been assigned to task '{$task->title}' by {$assignedBy->name}",
            'type' => 'task_assigned',
            'data' => [
                'task_id' => $task->id,
                'board_id' => $task->list->board_id,
                'assigned_by' => $assignedBy->id,
                'assigned_by_name' => $assignedBy->name,
            ]
        ]);
    }

    /**
     * Create comment notification
     */
    public static function createCommentNotification(Comment $comment, User $recipient): self
    {
        return self::create([
            'user_id' => $recipient->id,
            'title' => 'New Comment',
            'message' => "New comment on task '{$comment->task->title}' by {$comment->user->name}",
            'type' => 'task_comment',
            'data' => [
                'comment_id' => $comment->id,
                'task_id' => $comment->task_id,
                'board_id' => $comment->task->list->board_id,
                'commented_by' => $comment->user->id,
                'commented_by_name' => $comment->user->name,
            ]
        ]);
    }

    /**
     * Create mention notification
     */
    public static function createMentionNotification(Comment $comment, User $mentionedUser): self
    {
        return self::create([
            'user_id' => $mentionedUser->id,
            'title' => 'You were mentioned',
            'message' => "You were mentioned in a comment on task '{$comment->task->title}' by {$comment->user->name}",
            'type' => 'mention',
            'data' => [
                'comment_id' => $comment->id,
                'task_id' => $comment->task_id,
                'board_id' => $comment->task->list->board_id,
                'mentioned_by' => $comment->user->id,
                'mentioned_by_name' => $comment->user->name,
            ]
        ]);
    }

    /**
     * Create board invitation notification
     */
    public static function createBoardInvitation(BoardMember $invitation): self
    {
        return self::create([
            'user_id' => $invitation->user_id ?? null,
            'title' => 'Board Invitation',
            'message' => "You have been invited to join board '{$invitation->board->title}' by {$invitation->inviter->name}",
            'type' => 'board_invitation',
            'data' => [
                'board_id' => $invitation->board_id,
                'invitation_id' => $invitation->id,
                'invitation_token' => $invitation->invitation_token,
                'invited_by' => $invitation->invited_by,
                'invited_by_name' => $invitation->inviter->name,
                'role' => $invitation->role,
            ]
        ]);
    }

    /**
     * Create due date reminder
     */
    public static function createDueReminder(Task $task): self
    {
        return self::create([
            'user_id' => $task->assigned_to,
            'title' => 'Task Due Soon',
            'message' => "Task '{$task->title}' is due on {$task->due_date->format('M j, Y')}",
            'type' => 'due_reminder',
            'data' => [
                'task_id' => $task->id,
                'board_id' => $task->list->board_id,
                'due_date' => $task->due_date->toISOString(),
            ]
        ]);
    }

    /**
     * Create overdue reminder
     */
    public static function createOverdueReminder(Task $task): self
    {
        return self::create([
            'user_id' => $task->assigned_to,
            'title' => 'Task Overdue',
            'message' => "Task '{$task->title}' was due on {$task->due_date->format('M j, Y')} and is now overdue",
            'type' => 'overdue_reminder',
            'data' => [
                'task_id' => $task->id,
                'board_id' => $task->list->board_id,
                'due_date' => $task->due_date->toISOString(),
                'days_overdue' => now()->diffInDays($task->due_date),
            ]
        ]);
    }

    /**
     * Mark all notifications as read for a user
     */
    public static function markAllAsReadForUser(User $user): int
    {
        return self::where('user_id', $user->id)
                  ->whereNull('read_at')
                  ->update(['read_at' => now()]);
    }

    /**
     * Clean up old notifications
     */
    public static function cleanupOld(int $daysToKeep = 90): int
    {
        return self::where('created_at', '<', now()->subDays($daysToKeep))->delete();
    }

    /**
     * Get notification statistics for a user
     */
    public static function getUserStats(User $user): array
    {
        $notifications = self::forUser($user);
        
        return [
            'total' => $notifications->count(),
            'unread' => $notifications->unread()->count(),
            'high_priority' => $notifications->highPriority()->count(),
            'task_related' => $notifications->taskRelated()->count(),
            'board_related' => $notifications->boardRelated()->count(),
            'recent' => $notifications->recent(7)->count(),
        ];
    }

    // ==== EVENTS ====

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // When creating notification, ensure user_id is set
        static::creating(function ($notification) {
            if (is_null($notification->user_id) && auth()->check()) {
                $notification->user_id = auth()->id();
            }
        });
    }
}