<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'content',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==== RELATIONSHIPS ====

    /**
     * The task this comment belongs to
     */
    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * The user who wrote this comment
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The board this comment belongs to (through task -> list -> board)
     */
    public function board()
    {
        return $this->hasOneThrough(
            Board::class,
            Task::class,
            'id',           // Foreign key on tasks table
            'id',           // Foreign key on boards table  
            'task_id',      // Local key on comments table
            'list_id'       // Local key on tasks table
        )->join('lists', 'tasks.list_id', '=', 'lists.id')
          ->where('lists.board_id', '=', 'boards.id');
    }

    // ==== HELPER METHODS ====

    /**
     * Check if this comment was written by a specific user
     */
    public function isWrittenBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Check if user can edit this comment
     */
    public function canBeEditedBy(User $user): bool
    {
        // User can edit their own comments, or if they can edit the task
        return $this->isWrittenBy($user) || $this->task->canBeEditedBy($user);
    }

    /**
     * Check if user can delete this comment
     */
    public function canBeDeletedBy(User $user): bool
    {
        // User can delete their own comments, task creator, or board owner
        return $this->isWrittenBy($user) || 
               $this->task->isCreatedBy($user) ||
               $this->task->list->board->isOwnedBy($user);
    }

    /**
     * Check if user can view this comment
     */
    public function canBeViewedBy(User $user): bool
    {
        return $this->task->canBeViewedBy($user);
    }

    /**
     * Update comment content
     */
    public function updateContent(string $newContent): bool
    {
        return $this->update(['content' => $newContent]);
    }

    /**
     * Get comment age in human readable format
     */
    public function getAgeAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Check if comment was edited
     */
    public function wasEdited(): bool
    {
        return $this->created_at->ne($this->updated_at);
    }

    /**
     * Get formatted content with mentions highlighted
     */
    public function getFormattedContentAttribute(): string
    {
        $content = $this->content;
        
        // Replace @username mentions with links
        $content = preg_replace_callback(
            '/@(\w+)/',
            function ($matches) {
                $username = $matches[1];
                $user = User::where('name', 'like', "%{$username}%")->first();
                
                if ($user) {
                    return "<span class='mention' data-user-id='{$user->id}'>@{$user->name}</span>";
                }
                
                return $matches[0];
            },
            $content
        );

        // Convert URLs to links
        $content = preg_replace(
            '/(https?:\/\/[^\s]+)/',
            '<a href="$1" target="_blank" rel="noopener">$1</a>',
            $content
        );

        return $content;
    }

    /**
     * Extract mentioned users from comment
     */
    public function getMentionedUsers(): array
    {
        preg_match_all('/@(\w+)/', $this->content, $matches);
        
        if (empty($matches[1])) {
            return [];
        }

        return User::whereIn('name', $matches[1])
            ->orWhere(function($query) use ($matches) {
                foreach ($matches[1] as $username) {
                    $query->orWhere('name', 'like', "%{$username}%");
                }
            })
            ->get()
            ->toArray();
    }

    /**
     * Get word count of comment
     */
    public function getWordCountAttribute(): int
    {
        return str_word_count(strip_tags($this->content));
    }

    /**
     * Get character count of comment
     */
    public function getCharacterCountAttribute(): int
    {
        return strlen(strip_tags($this->content));
    }

    /**
     * Check if comment is long (more than X words)
     */
    public function isLong(int $wordLimit = 50): bool
    {
        return $this->word_count > $wordLimit;
    }

    /**
     * Get truncated content for previews
     */
    public function getTruncatedContent(int $limit = 100): string
    {
        return strlen($this->content) > $limit 
            ? substr($this->content, 0, $limit) . '...'
            : $this->content;
    }

    /**
     * Reply to this comment (create a new comment referencing this one)
     */
    public function reply(string $content, User $user = null): self
    {
        $user = $user ?? auth()->user();
        
        return self::create([
            'task_id' => $this->task_id,
            'user_id' => $user->id,
            'content' => "@{$this->user->name} {$content}",
        ]);
    }

    /**
     * Get activity summary for this comment
     */
    public function getActivitySummary(): string
    {
        $userName = $this->user->name ?? 'Unknown user';
        $taskTitle = $this->task->title ?? 'Unknown task';
        
        return "{$userName} commented on task '{$taskTitle}'";
    }

    // ==== SCOPES ====

    /**
     * Scope to get comments by a specific user
     */
    public function scopeByUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Scope to get comments on a specific task
     */
    public function scopeOnTask($query, Task $task)
    {
        return $query->where('task_id', $task->id);
    }

    /**
     * Scope to get recent comments
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to get comments in chronological order
     */
    public function scopeChronological($query)
    {
        return $query->orderBy('created_at', 'asc');
    }

    /**
     * Scope to get comments in reverse chronological order
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope to search comments by content
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where('content', 'like', "%{$search}%");
    }

    /**
     * Scope to get comments with mentions
     */
    public function scopeWithMentions($query)
    {
        return $query->where('content', 'like', '%@%');
    }

    /**
     * Scope to get comments mentioning a specific user
     */
    public function scopeMentioning($query, User $user)
    {
        return $query->where('content', 'like', "%@{$user->name}%");
    }

    /**
     * Scope to get comments on tasks in a specific board
     */
    public function scopeInBoard($query, Board $board)
    {
        return $query->whereHas('task.list', function($q) use ($board) {
            $q->where('board_id', $board->id);
        });
    }

    /**
     * Scope to get comments on tasks assigned to a user
     */
    public function scopeOnTasksAssignedTo($query, User $user)
    {
        return $query->whereHas('task', function($q) use ($user) {
            $q->where('assigned_to', $user->id);
        });
    }

    /**
     * Scope to get long comments
     */
    public function scopeLong($query, int $wordLimit = 50)
    {
        return $query->whereRaw('(LENGTH(content) - LENGTH(REPLACE(content, " ", "")) + 1) > ?', [$wordLimit]);
    }

    // ==== STATIC METHODS ====

    /**
     * Create a comment with automatic notification
     */
    public static function createWithNotification(array $data): self
    {
        $comment = self::create($data);
        
        // Send notifications to mentioned users
        $mentionedUsers = $comment->getMentionedUsers();
        foreach ($mentionedUsers as $userData) {
            $user = User::find($userData['id']);
            if ($user && $user->id !== $comment->user_id) {
                // Create notification (you'll implement this in Notification model)
                Notification::create([
                    'user_id' => $user->id,
                    'title' => 'You were mentioned in a comment',
                    'message' => "You were mentioned in a comment on task '{$comment->task->title}'",
                    'type' => 'mention',
                    'data' => json_encode([
                        'comment_id' => $comment->id,
                        'task_id' => $comment->task_id,
                        'mentioned_by' => $comment->user->name,
                    ])
                ]);
            }
        }

        // Notify task assignee if different from commenter
        if ($comment->task->assigned_to && 
            $comment->task->assigned_to !== $comment->user_id) {
            
            Notification::create([
                'user_id' => $comment->task->assigned_to,
                'title' => 'New comment on your task',
                'message' => "New comment on task '{$comment->task->title}'",
                'type' => 'task_comment',
                'data' => json_encode([
                    'comment_id' => $comment->id,
                    'task_id' => $comment->task_id,
                    'commented_by' => $comment->user->name,
                ])
            ]);
        }

        return $comment;
    }

    // ==== EVENTS ====

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // When creating a comment, set user_id automatically if not set
        static::creating(function ($comment) {
            if (is_null($comment->user_id) && auth()->check()) {
                $comment->user_id = auth()->id();
            }
        });

        // Log activity when comment is created
        static::created(function ($comment) {
            Activity::create([
                'user_id' => $comment->user_id,
                'board_id' => $comment->task->list->board_id,
                'subject_type' => Task::class,
                'subject_id' => $comment->task_id,
                'action' => 'commented',
                'description' => "Added a comment to task '{$comment->task->title}'"
            ]);
        });
    }
}