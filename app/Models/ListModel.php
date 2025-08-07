<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ListModel extends Model
{
    use HasFactory;

    protected $table = 'lists'; // Since the table name is 'lists'

    protected $fillable = [
        'board_id',
        'title',
        'position',
        'color',
    ];

    protected $casts = [
        'position' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==== RELATIONSHIPS ====

    /**
     * The board this list belongs to
     */
    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * All tasks in this list
     */
    public function tasks()
    {
        return $this->hasMany(Task::class, 'list_id')->orderBy('position');
    }

    /**
     * Tasks ordered by position
     */
    public function orderedTasks()
    {
        return $this->hasMany(Task::class, 'list_id')->orderBy('position');
    }

    /**
     * Tasks with specific status
     */
    public function tasksByStatus(string $status)
    {
        return $this->hasMany(Task::class, 'list_id')->where('status', $status);
    }

    // ==== HELPER METHODS ====

    /**
     * Get total tasks count
     */
    public function getTasksCountAttribute(): int
    {
        return $this->tasks()->count();
    }

    /**
     * Get completed tasks count
     */
    public function getCompletedTasksCountAttribute(): int
    {
        return $this->tasks()->where('status', 'done')->count();
    }

    /**
     * Get pending tasks count
     */
    public function getPendingTasksCountAttribute(): int
    {
        return $this->tasks()->where('status', 'pending')->count();
    }

    /**
     * Get in progress tasks count
     */
    public function getInProgressTasksCountAttribute(): int
    {
        return $this->tasks()->where('status', 'in_progress')->count();
    }

    /**
     * Get overdue tasks count
     */
    public function getOverdueTasksCountAttribute(): int
    {
        return $this->tasks()
            ->where('due_date', '<', now())
            ->where('status', '!=', 'done')
            ->count();
    }

    /**
     * Get list progress percentage
     */
    public function getProgressPercentageAttribute(): int
    {
        $total = $this->tasks_count;
        if ($total === 0) return 0;
        
        $completed = $this->completed_tasks_count;
        return round(($completed / $total) * 100);
    }

    /**
     * Check if list is empty
     */
    public function isEmpty(): bool
    {
        return $this->tasks_count === 0;
    }

    /**
     * Check if all tasks are completed
     */
    public function isCompleted(): bool
    {
        return $this->tasks_count > 0 && $this->completed_tasks_count === $this->tasks_count;
    }

    /**
     * Add a new task to this list
     */
    public function addTask(array $taskData): Task
    {
        // Set position to last if not provided
        if (!isset($taskData['position'])) {
            $taskData['position'] = $this->getNextTaskPosition();
        }

        $taskData['list_id'] = $this->id;
        
        return Task::create($taskData);
    }

    /**
     * Get next task position in this list
     */
    public function getNextTaskPosition(): int
    {
        $maxPosition = $this->tasks()->max('position') ?? 0;
        return $maxPosition + 1;
    }

    /**
     * Move this list to a new position
     */
    public function moveToPosition(int $newPosition): bool
    {
        $oldPosition = $this->position;
        
        if ($oldPosition === $newPosition) {
            return true; // No change needed
        }

        // Update other lists' positions
        if ($newPosition < $oldPosition) {
            // Moving up - shift others down
            $this->board->lists()
                ->where('position', '>=', $newPosition)
                ->where('position', '<', $oldPosition)
                ->increment('position');
        } else {
            // Moving down - shift others up
            $this->board->lists()
                ->where('position', '>', $oldPosition)
                ->where('position', '<=', $newPosition)
                ->decrement('position');
        }

        // Update this list's position
        return $this->update(['position' => $newPosition]);
    }

    /**
     * Duplicate this list (with or without tasks)
     */
    public function duplicate(bool $includeTasks = true, string $newTitle = null): self
    {
        $newList = $this->replicate();
        $newList->title = $newTitle ?? ($this->title . ' (Copy)');
        $newList->position = $this->board->getNextListPosition();
        $newList->save();

        if ($includeTasks) {
            foreach ($this->tasks as $task) {
                $task->duplicate($newList);
            }
        }

        return $newList;
    }

    /**
     * Archive this list (soft delete alternative)
     */
    public function archive(): bool
    {
        // You could add an 'archived' boolean column to implement this
        // For now, we'll use the position to "hide" archived lists
        return $this->update(['position' => -1]);
    }

    /**
     * Check if user can edit this list
     */
    public function canBeEditedBy(User $user): bool
    {
        return $this->board->canBeEditedBy($user);
    }

    /**
     * Check if user can view this list
     */
    public function canBeViewedBy(User $user): bool
    {
        return $this->board->canBeViewedBy($user);
    }

    /**
     * Get tasks assigned to a specific user
     */
    public function getTasksForUser(User $user)
    {
        return $this->tasks()->where('assigned_to', $user->id)->get();
    }

    /**
     * Get tasks with specific priority
     */
    public function getTasksByPriority(string $priority)
    {
        return $this->tasks()->where('priority', $priority)->get();
    }

    /**
     * Get tasks due within a date range
     */
    public function getTasksDueWithin(int $days)
    {
        return $this->tasks()
            ->whereBetween('due_date', [now(), now()->addDays($days)])
            ->where('status', '!=', 'done')
            ->get();
    }

    // ==== SCOPES ====

    /**
     * Scope to order lists by position
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }

    /**
     * Scope to get lists in specific board
     */
    public function scopeInBoard($query, Board $board)
    {
        return $query->where('board_id', $board->id);
    }

    /**
     * Scope to get non-archived lists
     */
    public function scopeActive($query)
    {
        return $query->where('position', '>=', 0);
    }

    /**
     * Scope to get archived lists
     */
    public function scopeArchived($query)
    {
        return $query->where('position', '<', 0);
    }

    /**
     * Scope to search lists by title
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where('title', 'like', "%{$search}%");
    }

    // ==== EVENTS ====

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // When creating a new list, set position automatically
        static::creating(function ($list) {
            if (is_null($list->position)) {
                $board = Board::find($list->board_id);
                $list->position = $board->getNextListPosition();
            }
        });

        // When deleting a list, adjust other lists' positions
        static::deleting(function ($list) {
            // Move all lists after this one, one position up
            $list->board->lists()
                ->where('position', '>', $list->position)
                ->decrement('position');
        });
    }
}