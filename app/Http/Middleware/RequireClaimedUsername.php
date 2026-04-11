<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks game actions until the authenticated user has claimed a
 * username. The ClaimUsernameModal on the frontend will already be
 * overlaying the UI (triggered via Inertia shared props), so this
 * middleware only exists as a server-side safety net for anyone
 * hitting the API or POST endpoints directly.
 */
class RequireClaimedUsername
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasClaimedUsername()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'You must claim a username before performing this action.',
                    'requires_username_claim' => true,
                ], 403);
            }

            return redirect()->route('dashboard')
                ->with('requires_username_claim', true);
        }

        return $next($request);
    }
}
