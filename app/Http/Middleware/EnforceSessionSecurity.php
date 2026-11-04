<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceSessionSecurity
{
    private const IDLE_TIMEOUT_SECONDS = 1500;

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $now = now();
        $lastActivity = (int) $request->session()->get('last_activity_at', 0);
        $sessionDay = $request->session()->get('session_day');
        $today = now('Asia/Kolkata')->toDateString();

        if ($lastActivity === 0 || ($now->timestamp - $lastActivity) >= self::IDLE_TIMEOUT_SECONDS) {
            return $this->logout($request, 'Your session expired after 25 minutes of inactivity. Please log in again.');
        }

        if ($sessionDay !== $today) {
            return $this->logout($request, 'For security, all users are logged out at midnight. Please log in again.');
        }

        $request->session()->put('last_activity_at', $now->timestamp);

        return $next($request);
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', $message);
    }
}
