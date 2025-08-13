<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BoardMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'board_id',
        'user_id',
        'invited_by',
        'email',
        'role',
        'status',
        'invitation_token',
        'invitation_expires_at',
    ];

    protected $casts = [
        'invitation_expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==== RELATIONSHIPS ====

    /**
     * The board this membership belongs to
     */
    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * The user who is a member
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user who sent the invitation
     */
    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    // ==== HELPER METHODS ====

    /**
     * Check if the membership is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the invitation is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the invitation was declined
     */
    public function isDeclined(): bool
    {
        return $this->status === 'declined';
    }

    /**
     * Check if the invitation has expired
     */
    public function isExpired(): bool
    {
        return $this->invitation_expires_at && 
               $this->invitation_expires_at->isPast();
    }

    /**
     * Check if user is the owner
     */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Check if user is a member
     */
    public function isMember(): bool
    {
        return $this->role === 'member';
    }

    /**
     * Check if user is a viewer
     */
    public function isViewer(): bool
    {
        return $this->role === 'viewer';
    }

    /**
     * Check if user can edit content
     */
    public function canEdit(): bool
    {
        return in_array($this->role, ['owner', 'member']);
    }

    /**
     * Check if user can manage members
     */
    public function canManageMembers(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Accept the invitation
     */
    public function accept(): bool
    {
        if (!$this->isPending() || $this->isExpired()) {
            return false;
        }

        return $this->update([
            'status' => 'active',
            'invitation_token' => null,
            'invitation_expires_at' => null,
            'email' => null,               // clear invite email after joining
        ]);
    }

    /**
     * Decline the invitation
     */
    public function decline(): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        return $this->update([
            'status' => 'declined',
            'invitation_token' => null,
            'invitation_expires_at' => null,
            'email' => null,               // clear invite email after decline
        ]);
    }

    /**
     * Generate invitation token
     */
    public function generateInvitationToken(): string
    {
        $token = Str::random(32);
        
        $this->update([
            'invitation_token' => $token,
            'invitation_expires_at' => now()->addDays(7), // Expires in 7 days
        ]);

        return $token;
    }

    /**
     * Resend invitation (regenerate token)
     */
    public function resendInvitation(): string
    {
        if (!$this->isPending()) {
            throw new \Exception('Can only resend pending invitations');
        }

        return $this->generateInvitationToken();
    }

    /**
     * Change member role
     */
    public function changeRole(string $newRole): bool
    {
        if (!in_array($newRole, ['owner', 'member', 'viewer'])) {
            throw new \InvalidArgumentException('Invalid role: ' . $newRole);
        }

        return $this->update(['role' => $newRole]);
    }

    /**
     * Get role display name
     */
    public function getRoleDisplayAttribute(): string
    {
        return match($this->role) {
            'owner' => 'Owner',
            'member' => 'Member',
            'viewer' => 'Viewer',
            default => 'Unknown'
        };
    }

    /**
     * Get status display name
     */
    public function getStatusDisplayAttribute(): string
    {
        return match($this->status) {
            'active' => 'Active',
            'pending' => 'Pending',
            'declined' => 'Declined',
            default => 'Unknown'
        };
    }

    /**
     * Get invitation URL
     */
    public function getInvitationUrlAttribute(): ?string
    {
        if (!$this->invitation_token) {
            return null;
        }

        return url("api/invitations/{$this->invitation_token}");
    }

    // ==== SCOPES ====

    /**
     * Scope to get active memberships only
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get pending memberships only
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to get declined memberships only
     */
    public function scopeDeclined($query)
    {
        return $query->where('status', 'declined');
    }

    /**
     * Scope to get memberships by role
     */
    public function scopeWithRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope to get owners only
     */
    public function scopeOwners($query)
    {
        return $query->where('role', 'owner');
    }

    /**
     * Scope to get members only (excluding owners and viewers)
     */
    public function scopeMembers($query)
    {
        return $query->where('role', 'member');
    }

    /**
     * Scope to get viewers only
     */
    public function scopeViewers($query)
    {
        return $query->where('role', 'viewer');
    }

    /**
     * Scope to get expired invitations
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'pending')
                    ->where('invitation_expires_at', '<', now());
    }

    /**
     * Scope to find by invitation token
     */
    public function scopeByToken($query, string $token)
    {
        return $query->where('invitation_token', $token)
                    ->where('status', 'pending')
                    ->where('invitation_expires_at', '>', now());
    }

    // ==== STATIC METHODS ====

    /**
     * Create a new invitation
     */
    public static function createInvitation(
        Board $board, 
        string $email, 
        string $role = 'member',
        User $invitedBy = null
    ): self {
        // Check if user already exists
        $user = User::where('email', $email)->first();

        $invitation = self::create([
            'board_id' => $board->id,
            'user_id' => $user?->id,
            'email' => $email,
            'role' => $role,
            'status' => 'pending',
            'invited_by' => $invitedBy?->id ?? auth()->id(),
        ]);

        $invitation->generateInvitationToken();

        return $invitation;
    }

    /**
     * Find invitation by token
     */
    public static function findByToken(string $token): ?self
    {
        return self::byToken($token)->first();
    }

    /**
     * Clean up expired invitations
     */
    public static function cleanupExpired(): int
    {
        return self::expired()->delete();
    }
/**
 * Clean up old declined or expired invitations
 */
public static function cleanupOldInvitations(int $days = 15): int
{
    $cutoffDate = now()->subDays($days);
    
    $declined = self::where('status', 'declined')
        ->where('updated_at', '<', $cutoffDate);
        
    $expired = self::where('status', 'pending')
        ->where('invitation_expires_at', '<', $cutoffDate);
    
    return $declined->delete() + $expired->delete();

}
}