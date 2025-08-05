<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
final class LoginController extends Controller
{
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_DECAY_MINUTES = 1;
    private const USER_FIELDS = ['id', 'name', 'email', 'avatar', 'created_at'];

    /**
     * Handle user login.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $this->validateCredentials($request);
        
        $this->checkRateLimit($credentials['email']);
        
        $user = $this->authenticateUser($credentials, $request);
        
        if (!$this->isEmailVerified($user)) {
            return $this->emailNotVerifiedResponse();
        }

        RateLimiter::clear($this->getRateLimitKey($credentials['email']));

        return $this->isApiRequest($request) 
            ? $this->apiLoginResponse($user)
            : $this->webLoginResponse($user, $request);
    }

    /**
     * Handle user logout.
     */
    public function logout(Request $request): JsonResponse
    {
        if ($this->isApiRequest($request)) {
            $request->user()->currentAccessToken()->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out successfully'
        ], Response::HTTP_OK);//200
    }

    /**
     * Validate login credentials.
     */
    private function validateCredentials(Request $request): array
    {
        return $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
        ]);
    }

    /**
     * Check if rate limit has been exceeded.
     */
    private function checkRateLimit(string $email): void
    {
        $rateLimitKey = $this->getRateLimitKey($email);
        
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::RATE_LIMIT_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            
            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Please try again in {$seconds} seconds."]
            ]);
        }
    }

    /**
     * Authenticate user with provided credentials.
     */
    private function authenticateUser(array $credentials, Request $request): User
    {
        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->handleFailedLogin($credentials['email'], $request);
        }

        return $user;
    }

    /**
     * Handle failed login attempt.
     */
    private function handleFailedLogin(string $email, Request $request): void
    {
        RateLimiter::hit($this->getRateLimitKey($email), self::RATE_LIMIT_DECAY_MINUTES * 60);
        
        Log::warning('Failed login attempt', [
            'email' => $email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.']
        ]);
    }

    /**
     * Check if user's email is verified.
     */
    private function isEmailVerified(User $user): bool
    {
        return !is_null($user->email_verified_at);
    }

    /**
     * Return response for unverified email.
     */
    private function emailNotVerifiedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Please verify your email address before logging in.',
            'email_verified' => false
        ], Response::HTTP_FORBIDDEN);//403
    }

    /**
     * Check if this is an API request.
     */
    private function isApiRequest(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*');
    }

    /**
     * Return API login response with token.
     */
    private function apiLoginResponse(User $user): JsonResponse
    {
        $token = $user->createToken('auth-token')->plainTextToken;
        
        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->only(self::USER_FIELDS),
            'email_verified' => true
        ], Response::HTTP_OK);
    }

    /**
     * Return web login response with session.
     */
    private function webLoginResponse(User $user, Request $request): JsonResponse
    {
        Auth::login($user, $request->filled('remember'));
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Login successful',
            'user' => $user->only(self::USER_FIELDS),
            'email_verified' => true
        ], Response::HTTP_OK);
    }

    /**
     * Get rate limit key for email.
     */
    private function getRateLimitKey(string $email): string
    {
        return "login-attempts:{$email}";
    }
}