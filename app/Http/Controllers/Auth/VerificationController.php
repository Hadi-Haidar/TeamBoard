<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\VerificationCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Illuminate\Support\Facades\Cache;

final class VerificationController extends Controller
{
    private const VERIFY_RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const VERIFY_RATE_LIMIT_DECAY_MINUTES = 10;
    private const RESEND_RATE_LIMIT_MAX_ATTEMPTS = 2;
    private const RESEND_RATE_LIMIT_DECAY_MINUTES = 10;
    private const VERIFICATION_CODE_LENGTH = 6;
    private const VERIFICATION_CODE_EXPIRY_MINUTES = 10;

    /**
     * Verify email with code.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $validated = $this->validateVerificationRequest($request);
        
        $this->checkVerificationRateLimit($validated['email']);
        
        $user = $this->findUserWithValidCode($validated['email'], $validated['code']);
        
        if (!$user) {
            $this->handleInvalidVerificationCode($validated['email']);
            
            return response()->json([
                'message' => 'Invalid or expired verification code.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->markEmailAsVerified($user);
        
        RateLimiter::clear($this->getVerificationRateLimitKey($validated['email']));

        return response()->json([
            'message' => 'Email verified successfully.',
            'status' => 'success'
        ], Response::HTTP_OK);
    }

    /**
     * Resend verification code.
     */
    public function resendVerificationCode(Request $request): JsonResponse
    {
        $validated = $this->validateResendRequest($request);
        
        $user = User::where('email', $validated['email'])->first();

        if ($this->isEmailAlreadyVerified($user)) {
            return response()->json([
                'message' => 'Email is already verified.'
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->checkResendRateLimit($validated['email']);

        try {
            $this->generateAndSendNewVerificationCode($user);
            
            $this->recordResendAttempt($validated['email']);

            return response()->json([
                'message' => 'Verification code sent successfully.',
                'status' => 'success'
            ], Response::HTTP_OK);

        } catch (Throwable $e) {
            $this->logResendFailure($validated['email'], $e);

            return response()->json([
                'message' => 'Failed to send verification code. Please try again later.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Check email verification status.
     */
    public function checkVerificationStatus(Request $request): JsonResponse
    {
    $validated = $this->validateStatusRequest($request);
    $email = $validated['email'];
    $cacheKey = "verification_status:{$email}";

    // Try to get the cached value
    $isVerified = Cache::get($cacheKey);

    // If not cached, check from DB
    if (is_null($isVerified)) {
        $user = User::where('email', $email)->first();

        $isVerified = $user && !is_null($user->email_verified_at);

        // Only cache if verified
        if ($isVerified) {
            Cache::put($cacheKey, true, now()->addMinutes(30));
        }
    }

    return response()->json([
        'is_verified' => $isVerified,
        'email' => $email
    ], Response::HTTP_OK);
   }


    /**
     * Validate email verification request.
     */
    private function validateVerificationRequest(Request $request): array
    {
        return $request->validate([
            'email' => 'required|email:rfc,dns|exists:users,email',
            'code' => 'required|string|size:6',
        ]);
    }

    /**
     * Validate resend verification request.
     */
    private function validateResendRequest(Request $request): array
    {
        return $request->validate([
            'email' => 'required|email:rfc,dns|exists:users,email',
        ]);
    }

    /**
     * Validate status check request.
     */
    private function validateStatusRequest(Request $request): array
    {
        return $request->validate([
            'email' => 'required|email:rfc,dns|exists:users,email',
        ]);
    }

    /**
     * Check verification rate limit.
     */
    private function checkVerificationRateLimit(string $email): void
    {
        $rateLimitKey = $this->getVerificationRateLimitKey($email);
        
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::VERIFY_RATE_LIMIT_MAX_ATTEMPTS)) {
            response()->json([
                'message' => 'Too many verification attempts. Please try again later.'
            ], Response::HTTP_TOO_MANY_REQUESTS)->throwResponse();
        }
    }

    /**
     * Check resend rate limit.
     */
    private function checkResendRateLimit(string $email): void
    {
        $rateLimitKey = $this->getResendRateLimitKey($email);
        
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::RESEND_RATE_LIMIT_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            
            response()->json([
                'message' => "Too many verification code requests. Try again in {$seconds} seconds."
            ], Response::HTTP_TOO_MANY_REQUESTS)->throwResponse();
        }
    }

    /**
     * Find user with valid verification code.
     */
    private function findUserWithValidCode(string $email, string $code): ?User
    {
        return User::where('email', $email)
            ->where('verification_code', $code)
            ->where('verification_code_expires_at', '>', now())
            ->first();
    }

    /**
     * Handle invalid verification code attempt.
     */
    private function handleInvalidVerificationCode(string $email): void
    {
        $rateLimitKey = $this->getVerificationRateLimitKey($email);
        RateLimiter::hit($rateLimitKey, self::VERIFY_RATE_LIMIT_DECAY_MINUTES * 60);
    }

    /**
     * Mark email as verified and clear verification data.
     */
    private function markEmailAsVerified(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->email_verified_at = now();
            $user->verification_code = null;
            $user->verification_code_expires_at = null;
            $user->save();
        });
    }

    /**
     * Check if email is already verified.
     */
    private function isEmailAlreadyVerified(User $user): bool
    {
        return !is_null($user->email_verified_at);
    }

    /**
     * Generate and send new verification code.
     */
    private function generateAndSendNewVerificationCode(User $user): void
    {
        DB::transaction(function () use ($user) {
            $verificationCode = $this->generateVerificationCode();
            
            $user->verification_code = $verificationCode;
            $user->verification_code_expires_at = now()->addMinutes(self::VERIFICATION_CODE_EXPIRY_MINUTES);
            $user->save();

            Mail::to($user->email)->send(new VerificationCode($verificationCode));
        });
    }

    /**
     * Generate a secure verification code.
     */
    private function generateVerificationCode(): string
    {
        $min = 10 ** (self::VERIFICATION_CODE_LENGTH - 1);
        $max = (10 ** self::VERIFICATION_CODE_LENGTH) - 1;
        
        return (string) random_int($min, $max);
    }

    /**
     * Record resend attempt for rate limiting.
     */
    private function recordResendAttempt(string $email): void
    {
        $rateLimitKey = $this->getResendRateLimitKey($email);
        RateLimiter::hit($rateLimitKey, self::RESEND_RATE_LIMIT_DECAY_MINUTES * 60);
    }

    /**
     * Log resend failure.
     */
    private function logResendFailure(string $email, Throwable $e): void
    {
        Log::error('Failed to resend verification code', [
            'email' => $email,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    /**
     * Get verification rate limit key.
     */
    private function getVerificationRateLimitKey(string $email): string
    {
        return "verify:{$email}";
    }

    /**
     * Get resend rate limit key.
     */
    private function getResendRateLimitKey(string $email): string
    {
        return "resend_verification:{$email}";
    }
}