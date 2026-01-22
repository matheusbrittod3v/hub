<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request and add security headers.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Enable XSS filter in browsers
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Referrer Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions Policy (previously Feature-Policy)
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Content Security Policy - Only in production (Vite dev server needs relaxed CSP)
        if (config('app.env') === 'production') {
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.googletagmanager.com https://www.google-analytics.com https://*.jads.co https://*.juicyads.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://www.googletagmanager.com",
                "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
                "img-src 'self' data: blob: https: http: https://www.googletagmanager.com https://www.google-analytics.com https://*.jads.co https://*.juicyads.com",
                "media-src 'self' blob: https: http:",
                "connect-src 'self' wss: https: https://www.google-analytics.com https://region1.google-analytics.com https://*.jads.co https://*.juicyads.com",
                "frame-src 'self' blob: data: https://*.juicyads.com https://*.jads.co https://www.googletagmanager.com",
                "child-src 'self' blob: data: https://*.juicyads.com https://*.jads.co",
                "frame-ancestors 'self'",
                "form-action 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                'upgrade-insecure-requests', // Automatically upgrades HTTP to HTTPS
            ]);
            $response->headers->set('Content-Security-Policy', $csp);

            // Strict Transport Security (only in production with HTTPS)
            if ($request->secure()) {
                $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
            }
        }

        return $response;
    }
}
