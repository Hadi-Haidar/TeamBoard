<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/login',                    // Login endpoint - no CSRF needed
        'api/register',                 // Registration endpoint - no CSRF needed
        'api/forgot-password',          // Password reset - no CSRF needed
        'api/reset-password',           // Password reset confirmation - no CSRF needed
        'api/verify-email',             // Email verification - no CSRF needed
        'api/resend-verification',      // Resend verification - no CSRF needed
        'api/auth/google',              // Google OAuth - no CSRF needed
        'api/auth/google/callback',     // Google OAuth callback - no CSRF needed
        'api/invitations/*/accept',     // Board invitation acceptance - uses token auth
        'api/invitations/*/decline',    // Board invitation decline - uses token auth
    ];
}
