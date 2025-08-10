<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Board extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'title',
        'description',
        'color',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==== RELATIONSHIPS ====

    /**
     * The user who owns this board
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * All members of this board (including owner)
     */
    public function members()
    {
        return $this->hasMany(BoardMember::class);
    }

    /**
     * Active members only
     */
    public function activeMembers()
    {
        return $this->hasMany(BoardMember::class)->where('status', 'active');
    }

    /**
     * Users who are members of this board (many-to-many)
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'board_members')
            ->withPivot(['role', 'status', 'invited_by'])
            ->withTimestamps()
            ->wherePivot('status', 'active');
    }

    /**
     * All lists in this board
     */
    public function lists()
    {
        return $this->hasMany(ListModel::class)->orderBy('position');
    }

    /**
     * All tasks in this board (through lists)
     */
    public function tasks()
    {
        return $this->hasManyThrough(Task::class, ListModel::class, 'board_id', 'list_id');
    }

    /**
     * Activities related to this board
     */
    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    // ==== HELPER METHODS ====

    /**
     * Check if user is the owner of this board
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    /**
     * Check if user is a member of this board
     */
    public function hasMember(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Get user's role in this board
     */
    public function getUserRole(User $user): ?string
    {
        // Board owner always has 'owner' role
        if ($this->isOwnedBy($user)) {
            return 'owner';
        }
        
        // Check membership table for other roles
        $membership = $this->members()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        return $membership?->role;
    }

    /**
     * Check if user can edit this board
     */
    public function canBeEditedBy(User $user): bool
    {
        $role = $this->getUserRole($user);
        return in_array($role, ['owner', 'member']);
    }

    /**
     * Check if user can view this board
     */
    public function canBeViewedBy(User $user): bool
    {
        $role = $this->getUserRole($user);
        return in_array($role, ['owner', 'member', 'viewer']);
    }

    /**
     * Check if user can manage members of this board
     */
    public function canManageMembersBy(User $user): bool
    {
        return $this->getUserRole($user) === 'owner';
    }

    /**
     * Add a member to this board
     */
    public function addMember(User $user, string $role = 'member', User $invitedBy = null): BoardMember
    {
        return $this->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'invited_by' => $invitedBy?->id ?? auth()->id(),
        ]);
    }

    /**
     * Remove a member from this board
     */
    public function removeMember(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->delete() > 0;
    }

    /**
     * Update member role
     */
    public function updateMemberRole(User $user, string $role): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->update(['role' => $role]) > 0;
    }

    /**
     * Get total tasks count in this board
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
     * Get board progress percentage
     */
    public function getProgressPercentageAttribute(): int
    {
        $total = $this->tasks_count;
        if ($total === 0) return 0;
        
        $completed = $this->completed_tasks_count;
        return round(($completed / $total) * 100);
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
     * Scope to get boards user has access to
     */
    public function scopeAccessibleByUser($query, User $user)
    {
        return $query->whereIn('id', function($subQuery) use ($user) {
            $subQuery->select('board_id')
                ->from('board_members')
                ->where('user_id', $user->id)
                ->where('status', 'active');
        });
    }

    /**
     * Scope to get boards owned by user
     */
    public function scopeOwnedBy($query, User $user)
    {
        return $query->where('owner_id', $user->id);
    }
}