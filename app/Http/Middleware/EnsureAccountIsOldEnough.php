<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsOldEnough
{
    /**
     * Minimum account age in hours required to create content
     */
    protected int $minimumHours = 48;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        // Check if account is old enough (48 hours = 2 days)
        $accountAge = $user->created_at->diffInHours(now());
        
        if ($accountAge < $this->minimumHours) {
            $hoursRemaining = $this->minimumHours - $accountAge;
            
            // For API requests, return JSON
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => "Sua conta precisa ter pelo menos 48 horas para criar conteúdo. Faltam {$hoursRemaining} horas.",
                    'hours_remaining' => $hoursRemaining,
                ], 403);
            }

            // For web requests, redirect back with error
            return redirect()->back()->withErrors([
                'account_age' => "Sua conta precisa ter pelo menos 48 horas para criar conteúdo. Faltam aproximadamente {$hoursRemaining} horas."
            ]);
        }

        return $next($request);
    }
}
