<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLandlordIsApproved
{
    /**
     * Route names a landlord awaiting approval may still reach: their status
     * page, email verification, logout, and the public pages. Everything
     * else, including Livewire's update endpoint, is redirected.
     */
    private const ALLOWED_ROUTES = [
        'landlord.verification',
        'landlord.verification.resubmit',
        'logout',
        'verification.*',
        'home',
        'listings.*',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->routeIs(self::ALLOWED_ROUTES) || ! $user->teamAwaitingApproval()) {
            return $next($request);
        }

        return redirect()->route('landlord.verification');
    }
}
