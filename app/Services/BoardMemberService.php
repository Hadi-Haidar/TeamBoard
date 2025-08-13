<?php

namespace App\Services;

use App\Models\Board;
use App\Models\BoardMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use App\Mail\BoardInvitationMail;

class BoardMemberService
{
    /**
     * Get board members with permissions and stats
     */
    public function getBoardMembers(User $user, string $boardId): array
    {
        $board = Board::with([
            'owner:id,name,avatar',
            'activeMembers:id,board_id,user_id,role,invited_by,created_at',
            'activeMembers.user:id,name,avatar,email',
            'activeMembers.inviter:id,name,avatar',
        ])->findOrFail($boardId);
        
        // Authorization check
        if (!$board->canBeViewedBy($user)) {
            // throw new \Illuminate\Auth\Access\AuthorizationException('You do not have permission to view this board.');
            throw new AuthorizationException('You do not have permission to view this board.');
        }
        
        $members = $this->formatMembersData($board->activeMembers);
        
        return [
            'board' => [
                'id' => $board->id,
                'title' => $board->title,
                'user_role' => $board->getUserRole($user),
                'can_manage_members' => $board->canManageMembersBy($user),
            ],
            'members' => $members,
            'stats' => [
                'total_members' => $members->count(),
                'owners' => $members->where('role', 'owner')->count(),
                'members' => $members->where('role', 'member')->count(),
                'viewers' => $members->where('role', 'viewer')->count(),
            ],
        ];
    }

    /**
     * Invite a member to the board
     */
    public function inviteMember(User $inviter, string $boardId, array $data): array
    {
        $board = Board::findOrFail($boardId);
        
        // Authorization check
        if (!$board->canManageMembersBy($inviter)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You do not have permission to invite members to this board.');
        }
        
        // Business logic validation
        $this->validateInvitation($data['email'], $inviter, $board);
        
        return DB::transaction(function () use ($board, $data, $inviter) {
            $invitation = BoardMember::createInvitation(
                $board,
                $data['email'],
                $data['role'],
                $inviter
            );
            
            $invitation->load(['user:id,name,avatar', 'inviter:id,name,avatar', 'board:id,title']);
            
            // Send email (with error handling but non-blocking)
            $this->sendInvitationEmail($invitation);
            
            return $this->formatInvitationData($invitation);
        });
    }

    /**
     * Get invitation by token
     */
    public function getInvitationByToken(string $token): array
    {
        $invitation = BoardMember::with([
            'board:id,title,description,color',
            'board.owner:id,name,avatar',
            'inviter:id,name,avatar',
            'user:id,name,avatar'
        ])->byToken($token)->firstOrFail();

        return [
            'email' => $invitation->email,
            'role' => $invitation->role,
            'status' => $invitation->status,
            'expires_at' => $invitation->invitation_expires_at->format('Y-m-d H:i:s'),
            'is_expired' => $invitation->isExpired(),
            'board' => [
                'title' => $invitation->board->title,
                'description' => $invitation->board->description,
            ],
            'invited_by' => $invitation->inviter->name,
        ];
    }

    /**
     * Accept invitation
     */
    public function acceptInvitation(string $token, array $userData = []): array
    {
        $invitation = BoardMember::with([
            'board:id,title,owner_id',
            'board.owner:id,name,avatar',
            'inviter:id,name,avatar',
            'user:id,name,email'
        ])->byToken($token)->firstOrFail();
        
        // Validation
        if (!$invitation->isPending()) {
            throw new InvalidArgumentException("This invitation has already been {$invitation->status}.");
        }
        
        if ($invitation->isExpired()) {
            throw new InvalidArgumentException('This invitation has expired.');
        }
        
        return DB::transaction(function () use ($invitation, $userData) {
            // Handle new user registration if needed
            if (!$invitation->user && !empty($userData)) {
                $user = $this->createUserFromInvitation($invitation, $userData);
                $invitation->update(['user_id' => $user->id]);
            }

            $invitation->accept();
            $invitation->refresh();
            $invitation->load(['board:id,title', 'user:id,name,avatar']);
            
            return [
                'id' => $invitation->id,
                'user_id' => $invitation->user_id,
                'role' => $invitation->role,
                'role_display' => $invitation->role_display,
                'status' => $invitation->status,
                'board' => [
                    'id' => $invitation->board->id,
                    'title' => $invitation->board->title,
                ],
                'user' => [
                    'id' => $invitation->user->id,
                    'name' => $invitation->user->name,
                    'avatar' => $invitation->user->avatar,
                ],
                'joined_at' => $invitation->updated_at->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * Decline invitation
     */
    public function declineInvitation(string $token): void
    {
        $invitation = BoardMember::byToken($token)->firstOrFail();
        
        if (!$invitation->isPending()) {
            throw new InvalidArgumentException("This invitation has already been {$invitation->status}.");
        }
        
        $invitation->decline();
    }

    /**
     * Update member role
     */
    public function updateMemberRole(User $requester, string $boardId, string $memberId, array $data): array
    {
        $board = Board::findOrFail($boardId);
        
        // Authorization check - only board owners can manage member roles
        if (!$board->canManageMembersBy($requester)) {
            throw new AuthorizationException('You do not have permission to manage members on this board.');
        }
        
        // Find the member to update
        $member = BoardMember::where('id', $memberId)
            ->where('board_id', $boardId)
            ->where('status', 'active')
            ->firstOrFail();
        
        // Business logic validation
        $this->validateRoleUpdate($member, $data['role'], $requester, $board);
        
        return DB::transaction(function () use ($member, $data) {
            // Update the role using model method
            $member->changeRole($data['role']);
            
            // Reload with relationships for response
            $member->refresh();
            $member->load(['user:id,name,avatar', 'inviter:id,name']);
            
            return [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user->name,
                'avatar' => $member->user->avatar,
                'email' => $member->user->email,
                'role' => $member->role,
                'role_display' => $member->role_display,
                'joined_at' => $member->created_at->format('Y-m-d H:i:s'),
                'invited_by' => $member->inviter ? [
                    'id' => $member->inviter->id,
                    'name' => $member->inviter->name,
                ] : null,
                'updated_at' => $member->updated_at->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * Remove member from board (owner kicks member OR member leaves)
     */
    public function removeMember(User $requester, string $boardId, string $memberId): void
    {
        $board = Board::findOrFail($boardId);
        
        // Find the member to remove
        $member = BoardMember::where('id', $memberId)
            ->where('board_id', $boardId)
            ->where('status', 'active')
            ->firstOrFail();
        
        // Business logic validation
        $this->validateMemberRemoval($member, $requester, $board);
        
        DB::transaction(function () use ($board, $member) {
            // Use the board model method for removal
            $board->removeMember($member->user);
        });
    }

    // ===================================
    // PRIVATE HELPER METHODS
    // ===================================

    private function formatMembersData($activeMembers)
    {
        return $activeMembers->map(function ($member) {
            return [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user->name,
                'avatar' => $member->user->avatar,
                'email' => $member->user->email,
                'role' => $member->role,
                'role_display' => $member->role_display,
                'joined_at' => $member->created_at->format('Y-m-d H:i:s'),
                'invited_by' => $member->inviter ? [
                    'id' => $member->inviter->id,
                    'name' => $member->inviter->name,
                ] : null,
            ];
        });
    }

    private function formatInvitationData($invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'role_display' => $invitation->role_display,
            'status' => $invitation->status,
            'status_display' => $invitation->status_display,
            'invited_by' => [
                'id' => $invitation->inviter->id,
                'name' => $invitation->inviter->name,
                'avatar' => $invitation->inviter->avatar,
            ],
            'invited_user' => $invitation->user ? [
                'id' => $invitation->user->id,
                'name' => $invitation->user->name,
                'avatar' => $invitation->user->avatar,
            ] : null,
            'invitation_token' => $invitation->invitation_token,
            'invitation_url' => $invitation->invitation_url,
            'expires_at' => $invitation->invitation_expires_at->format('Y-m-d H:i:s'),
            'created_at' => $invitation->created_at->format('Y-m-d H:i:s'),
        ];
    }

    private function validateInvitation(string $email, User $inviter, Board $board): void
    {
        if ($email === $inviter->email) {
            throw new InvalidArgumentException('You cannot invite yourself to the board.');
        }
        
        $existingMembership = BoardMember::where('board_id', $board->id)
            ->where('email', $email)
            ->first();
            
        if ($existingMembership) {
            if ($existingMembership->isActive()) {
                throw new InvalidArgumentException('User is already a member of this board.');
            }
            
            if ($existingMembership->isPending() && !$existingMembership->isExpired()) {
                throw new InvalidArgumentException('An invitation is already pending for this email.');
            }
            
            // Clean up expired/declined invitation
            $existingMembership->delete();
        }
    }

    private function createUserFromInvitation(BoardMember $invitation, array $userData): User
    {
        // Simple validation for new user data
        $validator = Validator::make($userData, [
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);
        
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return User::create([
            'name' => $userData['name'],
            'email' => $invitation->email,
            'password' => $userData['password'],
            'email_verified_at' => now(),
        ]);
    }

    private function sendInvitationEmail(BoardMember $invitation): void
    {
        try {
            Mail::to($invitation->email)->send(new BoardInvitationMail($invitation));
        } catch (\Throwable $e) {
            // Log but don't fail the invitation process
            Log::warning('Failed to send invitation email', [
                'invitation_id' => $invitation->id,
                'email' => $invitation->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function validateRoleUpdate(BoardMember $member, string $newRole, User $requester, Board $board): void
    {
        // Cannot change your own role
        if ($member->user_id === $requester->id) {
            throw new InvalidArgumentException('You cannot change your own role.');
        }
        
        // Cannot change the board owner's role
        if ($member->user_id === $board->owner_id) {
            throw new InvalidArgumentException('Cannot change the board owner\'s role.');
        }
        
        // Validate the new role
        if (!in_array($newRole, ['member', 'viewer'])) {
            throw new InvalidArgumentException('Invalid role. Only member and viewer roles can be assigned.');
        }
        
        // Check if role is actually changing
        if ($member->role === $newRole) {
            throw new InvalidArgumentException('User already has this role.');
        }
    }

    private function validateMemberRemoval(BoardMember $member, User $requester, Board $board): void
    {
        $isOwner = $board->canManageMembersBy($requester);
        $isSelfLeaving = $member->user_id === $requester->id;
        
        // Board owner cannot remove themselves (would orphan the board)
        if ($member->user_id === $board->owner_id) {
            throw new InvalidArgumentException('Board owner cannot be removed from the board.');
        }
        
        // Must be either the board owner (can remove others) OR the member themselves (can leave)
        if (!$isOwner && !$isSelfLeaving) {
            throw new AuthorizationException('You can only remove yourself from the board.');
        }
        
        // Additional check: ensure requester has some relationship to the board
        if (!$isOwner && !$board->hasMember($requester)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
    }
}
