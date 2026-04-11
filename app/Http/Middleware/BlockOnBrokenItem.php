<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any action while the player has a broken tech item pending
 * a repair-or-abandon decision. Only /items/repair and /items/abandon
 * remain accessible; everything else returns 423 Locked (API) or
 * redirects back with a flash message (web).
 *
 * The frontend is expected to overlay BrokenItemModal.vue whenever
 * the Inertia shared prop `broken_item_key` is non-null, so under
 * normal use the user can never even submit an action that would
 * hit this middleware — it's a server-side safety net.
 */
class BlockOnBrokenItem
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $player = $user?->player;

        if ($player !== null && $player->broken_item_key !== null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'You have a broken item. Repair or abandon it before doing anything else.',
                    'broken_item_key' => $player->broken_item_key,
                ], 423);
            }

            return redirect()->back()
                ->with('flash', ['broken_item_block' => $player->broken_item_key]);
        }

        return $next($request);
    }
}
