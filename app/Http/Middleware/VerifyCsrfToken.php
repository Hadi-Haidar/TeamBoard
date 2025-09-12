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
        // Temporarily exclude all API routes to test cross-domain issue
        'api/*',                        // TEMPORARY: Disable CSRF for all API routes
    ];
    
    /**
     * Determine if the HTTP request uses a 'read' verb.
     */
    protected function isReading($request)
    {
        return in_array($request->method(), ['HEAD', 'GET', 'OPTIONS']);
    }
    
    /**
     * Handle an incoming request.
     */
    public function handle($request, \Closure $next)
    {
        // For cross-domain requests from stateful domains, skip CSRF validation
        // This is a temporary fix for cross-subdomain CSRF token sharing issues
        $origin = $request->headers->get('Origin');
        $statefulDomains = config('sanctum.stateful');
        
        if ($origin && $this->isStatefulDomain($origin, $statefulDomains)) {
            return $next($request);
        }
        
        return parent::handle($request, $next);
    }
    
    /**
     * Check if the origin is a stateful domain
     */
    protected function isStatefulDomain($origin, $statefulDomains)
    {
        foreach ($statefulDomains as $domain) {
            if ($origin === 'https://' . trim($domain) || $origin === 'http://' . trim($domain)) {
                return true;
            }
        }
        return false;
    }
}
