<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Illuminate\Http\RedirectResponse;

final class GoogleController extends Controller
{
    private const USER_FIELDS = ['id', 'name', 'email', 'avatar', 'created_at'];
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirectToGoogle(): JsonResponse
    {
        $redirectUrl = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();
        
        return response()->json(['redirect_url' => $redirectUrl]);
    }

    /**
     * Handle Google callback and authenticate the user.
     */
    public function handleGoogleCallback(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            if (!$this->isValidGoogleUser($googleUser)) {
                if ($this->isApiRequest($request)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Invalid user data from Google'
                    ], Response::HTTP_BAD_REQUEST);
                } else {
                    // For web requests, redirect to frontend with error
                    return redirect(env('FRONTEND_URL') . '/signin?google_auth=error&message=' . urlencode('Invalid user data from Google'));
                }
            }

            $user = $this->findOrCreateUser($googleUser);
            
            return $this->isApiRequest($request) 
                ? $this->apiGoogleResponse($user)
                : $this->webGoogleResponse($user, $request);
            
        } catch (Throwable $e) {
            Log::error('Google OAuth failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            //for errors 
            if ($this->isApiRequest($request)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Authentication failed. Please try again.'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            } else {
                // For web requests, redirect to frontend with error
                return redirect(env('FRONTEND_URL') . '/signin?google_auth=error&message=' . urlencode('Authentication failed. Please try again.'));
            }
        }
    }

    /**
     * Validate Google user data.
     */
    private function isValidGoogleUser(SocialiteUser $googleUser): bool
    {
        return !empty($googleUser->id) && !empty($googleUser->email);
    }

    /**
     * Find existing user or create a new one.
     */
    private function findOrCreateUser(SocialiteUser $googleUser): User
    {
        return DB::transaction(function () use ($googleUser) {
            // First, try to find user by Google ID
            $user = User::where('google_id', $googleUser->id)->first();
            
            if ($user) {
                // Update existing Google user with latest info
                $user->update([
                    'name' => $googleUser->name,
                    'email' => $googleUser->email,
                    'avatar' => $googleUser->avatar,
                ]);
                return $user;
            }

            // Check if user exists with this email but no Google ID
            $existingUser = User::where('email', $googleUser->email)->first();
            
            if ($existingUser) {
                // Link existing account to Google
                $existingUser->update([
                    'google_id' => $googleUser->id,
                    'avatar' => $googleUser->avatar,
                    'is_google_user' => true,
                ]);
                return $existingUser;
            }

            // Create new user
            return User::create([
                'google_id' => $googleUser->id,
                'name' => $googleUser->name,
                'email' => $googleUser->email,
                'password' => Hash::make(Str::random(16)),
                'avatar' => $googleUser->avatar,
                'is_google_user' => true,
                'email_verified_at' => now(),
            ]);
        });
    }

    /**
     * Check if this is an API request.
     */
    private function isApiRequest(Request $request): bool
    {
        // Google OAuth callback should always be treated as a web request
        // because it comes from Google's redirect, not from frontend AJAX
        if ($request->is('api/auth/google/callback')) {
            return false;
        }
        
        if ($request->header('X-XSRF-TOKEN')) {
            return false;
        }
        
        return $request->expectsJson() || $request->is('api/*');
    }

    /**
     * Return API Google response with token.
     */
    private function apiGoogleResponse(User $user): JsonResponse
    {
        $token = $user->createToken('auth-token')->plainTextToken;
        
        return response()->json([
            'status' => 'success',
            'message' => 'User logged in successfully via Google',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->only(self::USER_FIELDS),
            'email_verified' => true
        ], Response::HTTP_OK);
    }

    /**
     * Return web Google response with session and redirect to frontend.
     */
    private function webGoogleResponse(User $user, Request $request): RedirectResponse
    {
        // For web requests, use session-based authentication (consistent with regular login)
        Auth::login($user, true); // true for "remember me" functionality
        $request->session()->regenerate();
        
        // Ensure session is saved immediately
        $request->session()->save();
        
        // Redirect to frontend with success indicator
        return redirect(env('FRONTEND_URL') . '/dashboard?google_auth=success');
    }
}