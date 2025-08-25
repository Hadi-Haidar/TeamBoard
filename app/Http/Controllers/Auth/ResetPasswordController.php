<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\RedirectResponse;//this is used to redirect the user to the reset password page

final class ResetPasswordController extends Controller
{
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_DECAY_MINUTES = 60;

    /**
     * Reset the given user's password.
     */
    public function reset(Request $request): JsonResponse
    {
        $validated = $this->validateResetRequest($request);
        
        $this->checkRateLimit($validated['email']);
        
        $this->resetUserPassword($validated, $request);
        
        RateLimiter::clear($this->getRateLimitKey($validated['email']));
        
        $this->logSuccessfulReset($validated['email'], $request->ip());

        return response()->json([
            'message' => 'Password has been reset successfully.',
            'status' => 'success'
        ], Response::HTTP_OK);
    }

    /**
     * Show the password reset form by redirecting to frontend. this is used to redirect the user to the reset password page
     */
    public function showResetForm(Request $request): RedirectResponse
    {
        $token = $request->query('token');
        $email = $request->query('email');
        
        // Validate required parameters
        if (!$token || !$email) {
            return redirect(env('FRONTEND_URL') . '/forgot-password')
                ->with('error', 'Invalid reset link. Please request a new password reset.');
        }
        
        // Redirect to React frontend with token and email
        return redirect(env('FRONTEND_URL') . '/reset-password?' . http_build_query([
            'token' => $token,
            'email' => $email
        ]));
    }

    /**
     * Validate password reset request.
     */
    private function validateResetRequest(Request $request): array
    {
        return $request->validate([
            'token' => 'required|string',
            'email' => 'required|email|max:255',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);
    }

    /**
     * Check if password reset rate limit has been exceeded.
     */
    private function checkRateLimit(string $email): void
    {
        $rateLimitKey = $this->getRateLimitKey($email);
        
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::RATE_LIMIT_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            
            throw ValidationException::withMessages([
                'email' => ["Too many reset attempts. Try again in {$seconds} seconds."]
            ]);
        }
    }

    /**
     * Reset the user's password using Laravel's Password facade.
     */
    private function resetUserPassword(array $validated, Request $request): void
    {
        $status = Password::reset(
            $validated,
            fn (User $user) => $this->updateUserPassword($user, $validated['password'])
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->handleResetFailure($validated['email'], $status, $request);
        }
    }

    /**
     * Update user's password and revoke existing tokens.
     */
    private function updateUserPassword(User $user, string $password): void
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ])->save();

        // Revoke all existing tokens for security
        $user->tokens()->delete();
    }

    /**
     * Handle password reset failure.
     */
    private function handleResetFailure(string $email, string $status, Request $request): void
    {
        $rateLimitKey = $this->getRateLimitKey($email);
        RateLimiter::hit($rateLimitKey, self::RATE_LIMIT_DECAY_MINUTES * 60);
        
        Log::warning('Password reset failed', [
            'email' => $email,
            'status' => $status,
            'ip' => $request->ip()
        ]);

        throw ValidationException::withMessages([
            'email' => [trans($status)]
        ]);
    }

    /**
     * Log successful password reset.
     */
    private function logSuccessfulReset(string $email, string $ip): void
    {
        Log::info('Password reset successful', [
            'email' => $email,
            'ip' => $ip
        ]);
    }

    /**
     * Get rate limit key for email.
     */
    private function getRateLimitKey(string $email): string
    {
        return "password-reset:{$email}";
    }
}