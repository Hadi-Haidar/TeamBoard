<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'list_id',
        'assigned_to',
        'created_by',
        'title',
        'description',
        'position',
        'due_date',
        'status',
        'priority',
        'tags',
    ];

    protected $casts = [
        'due_date' => 'date',
        'tags' => 'array',
        'position' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==== RELATIONSHIPS ====

    /**
     * The list this task belongs to
     */
    public function list()
    {
        return $this->belongsTo(ListModel::class, 'list_id');
    }

    /**
     * The board this task belongs to (through list)
     */
    public function board()
    {
        return $this->hasOneThrough(Board::class, ListModel::class, 'id', 'id', 'list_id', 'board_id');
    }

    /**
     * The user assigned to this task
     */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The user who created this task
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * All comments on this task
     */
    public function comments()
    {
        return $this->hasMany(Comment::class)->orderBy('created_at', 'asc');
    }

    /**
     * All attachments on this task
     */
    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * All activities related to this task
     */
    public function activities()
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    // ==== STATUS & PRIORITY HELPERS ====

    /**
     * Check if task is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if task is in progress
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * Check if task is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'done';
    }

    /**
     * Check if task is overdue
     */
    public function isOverdue(): bool
    {
        return $this->due_date && 
               $this->due_date->isPast() && 
               !$this->isCompleted();
    }

    /**
     * Check if task is due soon (within X days)
     */
    public function isDueSoon(int $days = 3): bool
    {
        if (!$this->due_date || $this->isCompleted()) {
            return false;
        }

        return $this->due_date->between(now(), now()->addDays($days));
    }

    /**
     * Check if task is high priority
     */
    public function isHighPriority(): bool
    {
        return $this->priority === 'high';
    }

    /**
     * Check if task is medium priority
     */
    public function isMediumPriority(): bool
    {
        return $this->priority === 'medium';
    }

    /**
     * Check if task is low priority
     */
    public function isLowPriority(): bool
    {
        return $this->priority === 'low';
    }

    // ==== TASK OPERATIONS ====

    /**
     * Mark task as completed
     */
    public function markAsCompleted(): bool
    {
        return $this->update(['status' => 'done']);
    }

    /**
     * Mark task as in progress
     */
    public function markAsInProgress(): bool
    {
        return $this->update(['status' => 'in_progress']);
    }

    /**
     * Mark task as pending
     */
    public function markAsPending(): bool
    {
        return $this->update(['status' => 'pending']);
    }

    /**
     * Assign task to a user
     */
    public function assignTo(User $user): bool
    {
        return $this->update(['assigned_to' => $user->id]);
    }

    /**
     * Remove assignment
     */
    public function unassign(): bool
    {
        return $this->update(['assigned_to' => null]);
    }

    /**
     * Move task to another list
     */
    public function moveToList(ListModel $newList): bool
    {
        $oldPosition = $this->position;
        $oldListId = $this->list_id;

        // Get new position in the target list
        $newPosition = $newList->getNextTaskPosition();

        // Update task
        $result = $this->update([
            'list_id' => $newList->id,
            'position' => $newPosition
        ]);

        if ($result) {
            // Adjust positions in old list
            Task::where('list_id', $oldListId)
                ->where('position', '>', $oldPosition)
                ->decrement('position');
        }

        return $result;
    }

    /**
     * Move task to a new position within the same list
     */
    public function moveToPosition(int $newPosition): bool
    {
        $oldPosition = $this->position;
        
        if ($oldPosition === $newPosition) {
            return true; // No change needed
        }

        // Update other tasks' positions
        if ($newPosition < $oldPosition) {
            // Moving up - shift others down
            Task::where('list_id', $this->list_id)
                ->where('position', '>=', $newPosition)
                ->where('position', '<', $oldPosition)
                ->increment('position');
        } else {
            // Moving down - shift others up
            Task::where('list_id', $this->list_id)
                ->where('position', '>', $oldPosition)
                ->where('position', '<=', $newPosition)
                ->decrement('position');
        }

        // Update this task's position
        return $this->update(['position' => $newPosition]);
    }

    /**
     * Duplicate this task
     */
    public function duplicate(ListModel $targetList = null, string $newTitle = null): self
    {
        $newTask = $this->replicate();
        $newTask->title = $newTitle ?? ($this->title . ' (Copy)');
        $newTask->list_id = $targetList?->id ?? $this->list_id;
        $newTask->position = ($targetList ?? $this->list)->getNextTaskPosition();
        $newTask->status = 'pending'; // Reset status for duplicated task
        $newTask->save();

        // Duplicate attachments if needed
        foreach ($this->attachments as $attachment) {
            $attachment->duplicate($newTask);
        }

        return $newTask;
    }

    /**
     * Add a tag to this task
     */
    public function addTag(string $tag): bool
    {
        $tags = $this->tags ?? [];
        
        if (!in_array($tag, $tags)) {
            $tags[] = $tag;
            return $this->update(['tags' => $tags]);
        }
        
        return true; // Tag already exists
    }

    /**
     * Remove a tag from this task
     */
    public function removeTag(string $tag): bool
    {
        $tags = $this->tags ?? [];
        $tags = array_values(array_filter($tags, fn($t) => $t !== $tag));
        
        return $this->update(['tags' => $tags]);
    }

    /**
     * Check if task has a specific tag
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags ?? []);
    }

    // ==== PERMISSION HELPERS ====

    /**
     * Check if user can edit this task
     */
    public function canBeEditedBy(User $user): bool
    {
        return $this->list->canBeEditedBy($user);
    }

    /**
     * Check if user can view this task
     */
    public function canBeViewedBy(User $user): bool
    {
        return $this->list->canBeViewedBy($user);
    }

    /**
     * Check if user is assigned to this task
     */
    public function isAssignedTo(User $user): bool
    {
        return $this->assigned_to === $user->id;
    }

    /**
     * Check if user created this task
     */
    public function isCreatedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    // ==== ATTRIBUTE ACCESSORS ====

    /**
     * Get comments count
     */
    public function getCommentsCountAttribute(): int
    {
        return $this->comments()->count();
    }

    /**
     * Get attachments count
     */
    public function getAttachmentsCountAttribute(): int
    {
        return $this->attachments()->count();
    }

    /**
     * Get status display name
     */
    public function getStatusDisplayAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Pending',
            'in_progress' => 'In Progress',
            'done' => 'Completed',
            default => 'Unknown'
        };
    }

    /**
     * Get priority display name
     */
    public function getPriorityDisplayAttribute(): string
    {
        return match($this->priority) {
            'low' => 'Low',
            'medium' => 'Medium', 
            'high' => 'High',
            default => 'Unknown'
        };
    }

    /**
     * Get priority color for UI
     */
    public function getPriorityColorAttribute(): string
    {
        return match($this->priority) {
            'low' => '#28a745',    // Green
            'medium' => '#ffc107', // Yellow
            'high' => '#dc3545',   // Red
            default => '#6c757d'   // Gray
        };
    }

    /**
     * Get days until due date
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (!$this->due_date) {
            return null;
        }

        return now()->diffInDays($this->due_date, false);
    }

    // ==== SCOPES ====

    /**
     * Scope to get tasks assigned to a user
     */
    public function scopeAssignedTo($query, User $user)
    {
        return $query->where('assigned_to', $user->id);
    }

    /**
     * Scope to get tasks created by a user
     */
    public function scopeCreatedBy($query, User $user)
    {
        return $query->where('created_by', $user->id);
    }

    /**
     * Scope to get tasks with specific status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get completed tasks
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'done');
    }

    /**
     * Scope to get pending tasks
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get in progress tasks
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope to get tasks with specific priority
     */
    public function scopeWithPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to get high priority tasks
     */
    public function scopeHighPriority($query)
    {
        return $query->where('priority', 'high');
    }

    /**
     * Scope to get overdue tasks
     */
    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
                    ->where('status', '!=', 'done');
    }

    /**
     * Scope to get tasks due soon
     */
    public function scopeDueSoon($query, int $days = 3)
    {
        return $query->whereBetween('due_date', [now(), now()->addDays($days)])
                    ->where('status', '!=', 'done');
    }

    /**
     * Scope to get tasks in specific list
     */
    public function scopeInList($query, ListModel $list)
    {
        return $query->where('list_id', $list->id);
    }

    /**
     * Scope to get tasks ordered by position
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position');
    }

    /**
     * Scope to search tasks by title or description
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%");
        });
    }

    /**
     * Scope to get tasks with specific tag
     */
    public function scopeWithTag($query, string $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    // ==== EVENTS ====

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // When creating a new task, set position automatically
        static::creating(function ($task) {
            if (is_null($task->position)) {
                $list = ListModel::find($task->list_id);
                $task->position = $list->getNextTaskPosition();
            }

            // Set created_by if not set
            if (is_null($task->created_by) && auth()->check()) {
                $task->created_by = auth()->id();
            }
        });

        // When deleting a task, adjust other tasks' positions
        static::deleting(function ($task) {
            // Move all tasks after this one, one position up
            Task::where('list_id', $task->list_id)
                ->where('position', '>', $task->position)
                ->decrement('position');
        });
    }
}