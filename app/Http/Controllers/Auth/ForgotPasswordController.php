<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ForgotPasswordController extends Controller
{
    private const RATE_LIMIT_MAX_ATTEMPTS = 3;
    private const RATE_LIMIT_DECAY_MINUTES = 60;

    /**
     * Send password reset link to the given user.
     */
    public function sendResetLinkEmail(Request $request): JsonResponse
    {
        $email = $this->validateRequest($request);
        
        $this->checkRateLimit($email);
        
        $this->sendPasswordResetLink($email);
        
        $this->recordAttempt($email);

        return response()->json([
            'message' => 'Password reset link sent successfully.',
            'status' => 'success'
        ], Response::HTTP_OK);
    }

    /**
     * Validate the incoming request.
     */
    private function validateRequest(Request $request): string
    {
        $validated = $request->validate([
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
                'exists:users,email'
            ]
        ], [
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.exists' => 'We could not find a user with that email address.',
        ]);

        return $validated['email'];
    }

    /**
     * Check if the user has exceeded rate limit.
     */
    private function checkRateLimit(string $email): void
    {
        $rateLimitKey = $this->getRateLimitKey($email);
        
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::RATE_LIMIT_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            
            throw ValidationException::withMessages([
                'email' => ["Too many password reset attempts. Try again in {$seconds} seconds."]
            ]);
        }
    }

    /**
     * Send the password reset link.
     */
    private function sendPasswordResetLink(string $email): void
    {
        $status = Password::sendResetLink(['email' => $email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [trans($status)]
            ]);
        }
    }

    /**
     * Record the password reset attempt.
     */
    private function recordAttempt(string $email): void
    {
        $rateLimitKey = $this->getRateLimitKey($email);
        RateLimiter::hit($rateLimitKey, self::RATE_LIMIT_DECAY_MINUTES * 60);
    }

    /**
     * Get the rate limit key for the given email.
     */
    private function getRateLimitKey(string $email): string
    {
        return "password-reset:{$email}";
    }
}