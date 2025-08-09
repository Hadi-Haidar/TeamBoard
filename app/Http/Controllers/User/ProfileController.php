<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Auth\VerificationController;
use App\Mail\EmailChangeVerification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ProfileController extends Controller
{
    private const PROFILE_FIELDS = [
        'id',
        'name', 
        'email',
        'avatar',
        'email_verified_at',
        'is_google_user',
        'created_at'
    ];

    /**
     * Show authenticated user's profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Get user stats based on your model relationships
        $stats = [
            'owned_boards_count' => $user->ownedBoards()->count(),
            'member_boards_count' => $user->boardMemberships()->where('status', 'active')->count(),
            'assigned_tasks_count' => $user->assignedTasks()->count(),
            'created_tasks_count' => $user->createdTasks()->count(),
            'comments_count' => $user->comments()->count(),
            'unread_notifications_count' => $user->unread_notifications_count,//laraveel magic
             //this Laravel Magic: Laravel automatically converts getUnreadNotificationsCountAttribute() to unread_notifications_count
        ];

        return response()->json([
            'message' => 'Profile retrieved successfully',
            'user' => $user->only(self::PROFILE_FIELDS),
            'stats' => $stats
        ], Response::HTTP_OK);
    }

    /**
     * Update user profile
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $this->validateProfileUpdate($request, $user);
        // Check if email change is requested
        $emailChangeRequested = isset($validated['email']) && $validated['email'] !== $user->email;
        try {
            DB::transaction(function () use ($user, $validated, $emailChangeRequested) {
                // Update name immediately (safe)
                if (isset($validated['name'])) {
                    $user->update(['name' => $validated['name']]);
                }
                
                // Handle email change request (don't update email yet!)
                if ($emailChangeRequested) {
                    $newEmail = $validated['email'];
                    
                    // Generate verification code using existing controller (smart reuse!)
                    $verificationController = new VerificationController();
                    $verificationCode = $verificationController->generateVerificationCode();
                    
                    // Store pending email change
                    $user->update([
                        'pending_email' => $newEmail,
                        'verification_code' => $verificationCode,
                        'verification_code_expires_at' => now()->addMinutes(10),
                    ]);
                    
                    // Send verification to NEW email
                    Mail::to($newEmail)->send(new EmailChangeVerification($user, $verificationCode, $user->email));
                }
            });
            
            $message = $emailChangeRequested 
                ? 'Profile updated. Please check your new email to verify the email change.'
                : 'Profile updated successfully.';
            
            return response()->json([
                'message' => $message,
                'user' => $user->fresh()->only(self::PROFILE_FIELDS),//fresh() is used to get the latest data from the database
                'email_change_pending' => $emailChangeRequested
            ], Response::HTTP_OK);//200
            
        } catch (\Throwable $e) {
            Log::error('Profile update failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
                'validated_data' => $validated
            ]);
            
            return response()->json([
                'message' => 'Failed to update profile. Please try again.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);//500
        }
    }

    /**
     * Confirm email change with verification code
     */
    public function confirmEmailChange(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'code' => 'required|string|size:6'
        ]);
        
        // Verify code and expiration
        if ($user->verification_code !== $validated['code'] || 
            $user->verification_code_expires_at < now()) {
            return response()->json([
                'message' => 'Invalid or expired verification code.'
            ], Response::HTTP_BAD_REQUEST);//400
        }
        
        try {
            DB::transaction(function () use ($user) {
                // Complete email change
                $user->update([
                    'email' => $user->pending_email,
                    'pending_email' => null,
                    'email_verified_at' => now(),
                    'verification_code' => null,
                    'verification_code_expires_at' => null,
                ]);
            });
            
            return response()->json([
                'message' => 'Email successfully changed and verified.',
                'user' => $user->fresh()->only(self::PROFILE_FIELDS)
            ], Response::HTTP_OK);
            
        } catch (\Throwable $e) {
            Log::error('Email confirmation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'error_type' => get_class($e)
            ]);
            
            return response()->json([
                'message' => 'Failed to confirm email change. Please try again.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Validate profile update request
     */
    private function validateProfileUpdate(Request $request, $user): array
    {
        return $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email:rfc,dns|max:255|unique:users,email,' . $user->id,
        ], [
            'name.required' => 'Name cannot be empty.',
            'email.required' => 'Email cannot be empty.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email is already taken.',
        ]);
    }
}
