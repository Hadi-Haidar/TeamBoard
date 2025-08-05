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
    public function handleGoogleCallback(Request $request): JsonResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            
            if (!$this->isValidGoogleUser($googleUser)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid user data from Google'
                ], Response::HTTP_BAD_REQUEST);
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
            
            return response()->json([
                'status' => 'error',
                'message' => 'Authentication failed. Please try again.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
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
     * Return web Google response with session.
     */
    private function webGoogleResponse(User $user, Request $request): JsonResponse
    {
        Auth::login($user, false); // Google users don't need "remember me"
        $request->session()->regenerate();

        return response()->json([
            'status' => 'success',
            'message' => 'User logged in successfully via Google',
            'user' => $user->only(self::USER_FIELDS),
            'email_verified' => true
        ], Response::HTTP_OK);
    }
}