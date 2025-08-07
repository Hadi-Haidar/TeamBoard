<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'board_id',
        'subject_type',
        'subject_id',
        'action',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==== RELATIONSHIPS ====

    /**
     * The user who performed this activity
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The board where this activity occurred
     */
    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * The subject of this activity (polymorphic)
     * Can be Task, Board, List, etc.
     */
    public function subject()
    {
        return $this->morphTo();
    }

    // ==== HELPER METHODS ====

    /**
     * Get the activity age in human readable format
     */
    public function getAgeAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Check if this activity was performed by a specific user
     */
    public function isPerformedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Check if this activity occurred in a specific board
     */
    public function isInBoard(Board $board): bool
    {
        return $this->board_id === $board->id;
    }

    /**
     * Check if this activity is about a specific subject
     */
    public function isAbout($subject): bool
    {
        return $this->subject_type === get_class($subject) && 
               $this->subject_id === $subject->id;
    }

    /**
     * Get activity icon based on action
     */
    public function getIconAttribute(): string
    {
        return match($this->action) {
            'created' => '➕',
            'updated', 'edited' => '✏️',
            'deleted' => '🗑️',
            'completed', 'finished' => '✅',
            'assigned' => '👤',
            'moved' => '↔️',
            'commented' => '💬',
            'uploaded_file', 'attached' => '📎',
            'invited' => '📧',
            'joined' => '🚪',
            'left' => '🚶',
            'archived' => '📦',
            'restored' => '🔄',
            'duplicated' => '📋',
            'priority_changed' => '⚡',
            'due_date_set' => '📅',
            'status_changed' => '🔄',
            default => '📝'
        };
    }

    /**
     * Get activity color based on action
     */
    public function getColorAttribute(): string
    {
        return match($this->action) {
            'created' => '#28a745',      // Green
            'completed' => '#28a745',    // Green
            'updated', 'edited' => '#17a2b8', // Blue
            'deleted' => '#dc3545',      // Red
            'assigned' => '#6f42c1',     // Purple
            'moved' => '#fd7e14',        // Orange
            'commented' => '#20c997',    // Teal
            'uploaded_file' => '#6c757d', // Gray
            'invited' => '#007bff',      // Blue
            default => '#6c757d'         // Gray
        };
    }

    /**
     * Get formatted description with links
     */
    public function getFormattedDescriptionAttribute(): string
    {
        $description = $this->description;
        
        // Replace task titles with links
        if ($this->subject_type === Task::class && $this->subject) {
            $taskTitle = $this->subject->title;
            $taskUrl = route('tasks.show', $this->subject->id);
            $description = str_replace(
                "'{$taskTitle}'",
                "<a href='{$taskUrl}' class='activity-link'>'{$taskTitle}'</a>",
                $description
            );
        }

        // Replace board titles with links
        if ($this->board) {
            $boardTitle = $this->board->title;
            $boardUrl = route('boards.show', $this->board->id);
            $description = str_replace(
                "'{$boardTitle}'",
                "<a href='{$boardUrl}' class='activity-link'>'{$boardTitle}'</a>",
                $description
            );
        }

        return $description;
    }

    /**
     * Get activity summary for notifications
     */
    public function getSummaryAttribute(): string
    {
        $userName = $this->user->name ?? 'Unknown user';
        $action = $this->action;
        
        return match($this->subject_type) {
            Task::class => "{$userName} {$action} a task",
            Board::class => "{$userName} {$action} a board",
            ListModel::class => "{$userName} {$action} a list",
            Comment::class => "{$userName} {$action} a comment",
            default => "{$userName} {$action} something"
        };
    }

    /**
     * Check if user can view this activity
     */
    public function canBeViewedBy(User $user): bool
    {
        // User can view activity if they have access to the board
        return $this->board->canBeViewedBy($user);
    }

    /**
     * Get related users for this activity (for notifications)
     */
    public function getRelatedUsers(): array
    {
        $users = collect([$this->user]);

        // Add subject-related users
        if ($this->subject_type === Task::class && $this->subject) {
            if ($this->subject->assigned_to) {
                $users->push($this->subject->assignedUser);
            }
            if ($this->subject->created_by) {
                $users->push($this->subject->creator);
            }
        }

        // Add board members
        $boardMembers = $this->board->users;
        $users = $users->merge($boardMembers);

        return $users->unique('id')->filter()->values()->toArray();
    }

    // ==== SCOPES ====

    /**
     * Scope to get activities by a specific user
     */
    public function scopeByUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Scope to get activities in a specific board
     */
    public function scopeInBoard($query, Board $board)
    {
        return $query->where('board_id', $board->id);
    }

    /**
     * Scope to get activities about a specific subject
     */
    public function scopeAbout($query, $subject)
    {
        return $query->where('subject_type', get_class($subject))
                    ->where('subject_id', $subject->id);
    }

    /**
     * Scope to get activities with specific action
     */
    public function scopeWithAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to get recent activities
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to get activities in chronological order
     */
    public function scopeChronological($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope to get activities about tasks
     */
    public function scopeAboutTasks($query)
    {
        return $query->where('subject_type', Task::class);
    }

    /**
     * Scope to get activities about boards
     */
    public function scopeAboutBoards($query)
    {
        return $query->where('subject_type', Board::class);
    }

    /**
     * Scope to get activities about lists
     */
    public function scopeAboutLists($query)
    {
        return $query->where('subject_type', ListModel::class);
    }

    /**
     * Scope to get activities about comments
     */
    public function scopeAboutComments($query)
    {
        return $query->where('subject_type', Comment::class);
    }

    /**
     * Scope to get activities for boards user has access to
     */
    public function scopeAccessibleByUser($query, User $user)
    {
        return $query->whereIn('board_id', function($subQuery) use ($user) {
            $subQuery->select('board_id')
                ->from('board_members')
                ->where('user_id', $user->id)
                ->where('status', 'active');
        });
    }

    /**
     * Scope to search activities by description
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where('description', 'like', "%{$search}%");
    }

    /**
     * Scope to get activities between dates
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to group activities by date
     */
    public function scopeGroupedByDate($query)
    {
        return $query->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                    ->groupBy('date')
                    ->orderBy('date', 'desc');
    }

    // ==== STATIC METHODS ====

    /**
     * Log a task activity
     */
    public static function logTaskActivity(
        Task $task, 
        string $action, 
        string $description, 
        User $user = null
    ): self {
        return self::create([
            'user_id' => $user?->id ?? auth()->id(),
            'board_id' => $task->list->board_id,
            'subject_type' => Task::class,
            'subject_id' => $task->id,
            'action' => $action,
            'description' => $description,
        ]);
    }

    /**
     * Log a board activity
     */
    public static function logBoardActivity(
        Board $board, 
        string $action, 
        string $description, 
        User $user = null
    ): self {
        return self::create([
            'user_id' => $user?->id ?? auth()->id(),
            'board_id' => $board->id,
            'subject_type' => Board::class,
            'subject_id' => $board->id,
            'action' => $action,
            'description' => $description,
        ]);
    }

    /**
     * Log a list activity
     */
    public static function logListActivity(
        ListModel $list, 
        string $action, 
        string $description, 
        User $user = null
    ): self {
        return self::create([
            'user_id' => $user?->id ?? auth()->id(),
            'board_id' => $list->board_id,
            'subject_type' => ListModel::class,
            'subject_id' => $list->id,
            'action' => $action,
            'description' => $description,
        ]);
    }

    /**
     * Log a comment activity
     */
    public static function logCommentActivity(
        Comment $comment, 
        string $action, 
        string $description, 
        User $user = null
    ): self {
        return self::create([
            'user_id' => $user?->id ?? auth()->id(),
            'board_id' => $comment->task->list->board_id,
            'subject_type' => Comment::class,
            'subject_id' => $comment->id,
            'action' => $action,
            'description' => $description,
        ]);
    }

    /**
     * Get activity statistics for a board
     */
    public static function getBoardStats(Board $board, int $days = 30): array
    {
        $activities = self::inBoard($board)->recent($days);
        
        return [
            'total_activities' => $activities->count(),
            'unique_users' => $activities->distinct('user_id')->count('user_id'),
            'tasks_created' => $activities->aboutTasks()->withAction('created')->count(),
            'tasks_completed' => $activities->aboutTasks()->withAction('completed')->count(),
            'comments_added' => $activities->aboutComments()->withAction('commented')->count(),
            'files_uploaded' => $activities->withAction('uploaded_file')->count(),
            'daily_breakdown' => $activities->groupedByDate()->get()->toArray(),
        ];
    }

    /**
     * Get user activity summary
     */
    public static function getUserStats(User $user, int $days = 30): array
    {
        $activities = self::byUser($user)->recent($days);
        
        return [
            'total_activities' => $activities->count(),
            'boards_active_in' => $activities->distinct('board_id')->count('board_id'),
            'tasks_created' => $activities->aboutTasks()->withAction('created')->count(),
            'tasks_completed' => $activities->aboutTasks()->withAction('completed')->count(),
            'comments_made' => $activities->aboutComments()->withAction('commented')->count(),
            'files_uploaded' => $activities->withAction('uploaded_file')->count(),
            'most_active_day' => $activities->groupedByDate()->first(),
        ];
    }

    /**
     * Clean up old activities
     */
    public static function cleanupOld(int $daysToKeep = 365): int
    {
        return self::where('created_at', '<', now()->subDays($daysToKeep))->delete();
    }

    // ==== EVENTS ====

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // When creating activity, set user_id automatically if not set
        static::creating(function ($activity) {
            if (is_null($activity->user_id) && auth()->check()) {
                $activity->user_id = auth()->id();
            }
        });
    }
}