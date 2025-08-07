<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\VerificationCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request; //for Api request
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response; //for general HTTP
use Throwable;

final class RegisterController extends Controller
{
    private const RATE_LIMIT_MAX_ATTEMPTS = 3;
    private const RATE_LIMIT_DECAY_MINUTES = 60;
    private const VERIFICATION_CODE_LENGTH = 6;
    private const VERIFICATION_CODE_EXPIRY_MINUTES = 10;
    private const USER_RESPONSE_FIELDS = ['id', 'name', 'email', 'created_at'];

    /**
     * Register a new user.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $this->validateRegistration($request);
        
        $this->checkRateLimit($request->ip());

        try {
            $user = $this->createUserWithVerification($validated);
            
            $this->sendVerificationEmail($user);
            
            $this->recordAttempt($request->ip());

            return response()->json([
                'message' => 'Registration successful. Please check your email for verification code.',
                'user' => $user->only(self::USER_RESPONSE_FIELDS),// it takes only the fields that are specified in the array $user
            ], Response::HTTP_CREATED);//201

        } catch (Throwable $e) {
            $this->handleRegistrationFailure($validated['email'], $request->ip(), $e);
            
            return response()->json([
                'message' => 'Registration failed. Please try again later.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);//500
        }
    }

    /**
     * Validate registration request.
     */
    private function validateRegistration(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email:rfc,dns|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
    }

    /**
     * Check if registration rate limit has been exceeded.
     */
    private function checkRateLimit(string $ip): void
    {
        $rateLimitKey = $this->getRateLimitKey($ip);
        
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::RATE_LIMIT_MAX_ATTEMPTS)) {//bolean
            $seconds = RateLimiter::availableIn($rateLimitKey);
            
            response()->json([
                'message' => "Too many registration attempts. Try again in {$seconds} seconds."
            ], Response::HTTP_TOO_MANY_REQUESTS)->throwResponse();//429
        }
    }

    /**
     * Create user with verification code in a database transaction.
     */
    private function createUserWithVerification(array $validated): User
    {
        return DB::transaction(function () use ($validated) {
            return User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'verification_code' => $this->generateVerificationCode(),
                'verification_code_expires_at' => now()->addMinutes(self::VERIFICATION_CODE_EXPIRY_MINUTES),
            ]);
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
     * Send verification email to user.
     */
    private function sendVerificationEmail(User $user): void
    {
        Mail::to($user->email)->send(new VerificationCode($user, $user->verification_code));
    }

    /**
     * Record registration attempt for rate limiting.
     */
    private function recordAttempt(string $ip): void
    {
        $rateLimitKey = $this->getRateLimitKey($ip);
        RateLimiter::hit($rateLimitKey, self::RATE_LIMIT_DECAY_MINUTES * 60);//hit is like a counter incrase every time the user register 
    }

        /**
     * Get rate limit key for IP address.like register:192.168.1.1 it like a counter incrase every time the user register 
     */
    private function getRateLimitKey(string $ip): string
    {
        return "register:{$ip}";
    }

    /**
     * Handle registration failure with logging and rate limiting.
     */
    private function handleRegistrationFailure(string $email, string $ip, Throwable $e): void
    {
        Log::error('Registration failed', [
            'email' => $email,
            'ip' => $ip,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->recordAttempt($ip);
    }


}