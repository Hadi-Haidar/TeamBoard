<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\BoardMemberService;
use App\Http\Requests\InviteMemberRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use App\Models\Board;
use App\Models\BoardMember;
use Illuminate\Support\Facades\DB;//to use transaction and also to have direct access to the database

class BoardMemberController extends Controller
{
    public function __construct(
        private BoardMemberService $boardMemberService
    ) {}

    /**
     * Display board members with their details and permissions
     */
    public function index(Request $request, string $boardId): JsonResponse
    {
        try {
            $result = $this->boardMemberService->getBoardMembers($request->user(), $boardId);
            
            return response()->json([
                'message' => 'Board members retrieved successfully',
                'data' => $result
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Board not found.'], Response::HTTP_NOT_FOUND);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\Throwable $e) {
            Log::error('Board members retrieval failed', [
                'board_id' => $boardId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to retrieve board members.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Invite a user to join the board
     */
    public function invite(InviteMemberRequest $request, string $boardId): JsonResponse
    {
        try {
            $invitation = $this->boardMemberService->inviteMember(
                $request->user(),
                $boardId,
                $request->validated()
            );
            
            return response()->json([
                'message' => 'Invitation sent successfully',
                'data' => $invitation
            ], Response::HTTP_CREATED);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Board not found.'], Response::HTTP_NOT_FOUND);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('Board invitation failed', [
                'board_id' => $boardId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to send invitation.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Show invitation details by token
     */
    public function showInvitation(string $token): JsonResponse
    {
        try {
            $invitation = $this->boardMemberService->getInvitationByToken($token);
            
            return response()->json([
                'message' => 'Invitation details retrieved successfully',
                'data' => $invitation
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Invalid or expired invitation token.'], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            Log::error('Invitation details retrieval failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to retrieve invitation details.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Accept board invitation
     */
    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        try {
            $membership = $this->boardMemberService->acceptInvitation($token, $request->all());
            
            return response()->json([
                'message' => 'Invitation accepted successfully! Welcome to the board.',
                'data' => $membership
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Invalid or expired invitation token.'], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error('Invitation acceptance failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to accept invitation.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Decline board invitation
     */
    public function declineInvitation(string $token): JsonResponse
    {
        try {
            $this->boardMemberService->declineInvitation($token);
            
            return response()->json([
                'message' => 'Invitation declined successfully.'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Invalid or expired invitation token.'], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('Invitation decline failed', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to decline invitation.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update member role
     */
    public function update(Request $request, string $boardId, string $memberId): JsonResponse
    {
        try {
            // Simple validation - inline since it's just one field
            $validated = $request->validate([
                'role' => 'required|in:member,viewer',
            ]);
            
            $updatedMember = $this->boardMemberService->updateMemberRole(
                $request->user(),
                $boardId,
                $memberId,
                $validated
            );
            
            return response()->json([
                'message' => 'Member role updated successfully',
                'data' => $updatedMember
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Board or member not found.'], Response::HTTP_NOT_FOUND);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            Log::error('Member role update failed', [
                'board_id' => $boardId,
                'member_id' => $memberId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to update member role.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove member from board (owner removes member OR member leaves)
     */
    public function destroy(Request $request, string $boardId, string $memberId): JsonResponse
    {
        try {
            $this->boardMemberService->removeMember(
                $request->user(),
                $boardId,
                $memberId
            );
            
            // Determine the appropriate message based on who performed the action
            $member = BoardMember::where('id', $memberId)
                ->where('board_id', $boardId)
                ->first();
                
            $message = $member && $member->user_id === $request->user()->id
                ? 'You have left the board successfully.'
                : 'Member removed from board successfully.';
            
            return response()->json([
                'message' => $message
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Board or member not found.'], Response::HTTP_NOT_FOUND);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('Member removal failed', [
                'board_id' => $boardId,
                'member_id' => $memberId,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to remove member.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
