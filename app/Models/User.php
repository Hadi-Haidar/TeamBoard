<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'verification_code',
        'verification_code_expires_at',
        'google_id',
        'avatar',
        'is_google_user',
        'email_verified_at',
        'pending_email',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'verification_code',
        'verification_code_expires_at',
        'google_id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verification_code_expires_at' => 'datetime',
            'password' => 'hashed',
            'is_google_user' => 'boolean',
        ];
    }

    // ==== RELATIONSHIPS ====

    /**
     * Boards owned by this user
     */
    public function ownedBoards()
    {
        return $this->hasMany(Board::class, 'owner_id'); // ✅ This refers to boards.owner_id
    }

    /**
     * Board memberships (boards user is member of)
     */
    public function boardMemberships()
    {
        return $this->hasMany(BoardMember::class);
    }

    /**
     * All boards user has access to (owned + member)
     */
    public function boards()
    {
        return $this->belongsToMany(Board::class, 'board_members')
            ->withPivot(['role', 'status'])
            ->wherePivot('status', 'active');
    }

    /**
     * Tasks assigned to this user
     */
    public function assignedTasks()
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    /**
     * Tasks created by this user
     */
    public function createdTasks()
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /**
     * Comments made by this user
     */
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Attachments uploaded by this user
     */
    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Activities performed by this user
     */
    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Notifications for this user
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Board invitations sent by this user
     */
    public function sentInvitations()
    {
        return $this->hasMany(BoardMember::class, 'invited_by');
    }

    // ==== HELPER METHODS ====

    /**
     * Check if user owns a board
     */
    public function ownsBoard(Board $board): bool
    {
        return $this->id === $board->owner_id;
    }

    /**
     * Check if user is member of a board
     */
    public function isMemberOfBoard(Board $board): bool
    {
        return $this->boardMemberships()
            ->where('board_id', $board->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Get user's role in a board
     */
    public function getRoleInBoard(Board $board): ?string
    {
        $membership = $this->boardMemberships()
            ->where('board_id', $board->id)
            ->where('status', 'active')
            ->first();

        return $membership?->role;
    }

    /**
     * Check if user can edit a board
     */
    public function canEditBoard(Board $board): bool
    {
        $role = $this->getRoleInBoard($board);
        return in_array($role, ['owner', 'member']);
    }

    /**
     * Get unread notifications count
     */
    public function getUnreadNotificationsCountAttribute(): int
    {
        return $this->notifications()->whereNull('read_at')->count();
    }
}
