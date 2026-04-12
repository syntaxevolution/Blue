<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects state-changing actions (POST/PUT/PATCH/DELETE) while the
 * player has a broken tech item pending a repair-or-abandon decision.
 *
 * READ requests (GET/HEAD/OPTIONS) are intentionally allowed through
 * so the player can still navigate to /map and see the BrokenItemModal
 * overlay rendered from Inertia shared props. Previously this middleware
 * blocked EVERY method, which caused an infinite redirect loop:
 *
 *   1. User drills, wear-break sets broken_item_key
 *   2. Controller returns redirect to /map
 *   3. Client follows → GET /map → middleware blocks → redirect back
 *   4. "Back" target is /map (same page the user was on) → loop
 *   5. Inertia XHR chain stalls until throttle kicks in, the page
 *      appears frozen, user thinks there's a network issue
 *
 * Write-only blocking preserves the safety-net behaviour (you can't
 * drill or attack with a broken rig) while letting the modal actually
 * render so the player can respond to it. The repair/abandon routes
 * are explicitly registered OUTSIDE this middleware group so those
 * remain usable.
 */
class BlockOnBrokenItem
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        // Always let reads through — they render pages (including the
        // modal overlay) and the worst they can do is query data the
        // player is already entitled to see.
        if (! in_array($request->method(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        $user = $request->user();
        $player = $user?->player;

        if ($player !== null && $player->broken_item_key !== null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'You have a broken item. Repair or abandon it before doing anything else.',
                    'broken_item_key' => $player->broken_item_key,
                ], 423);
            }

            // Route to /map rather than back() — back() could loop if
            // the user's previous page was also a blocked write route.
            // /map is always the safe landing page because GETs bypass
            // this middleware entirely now.
            return redirect()->route('map.show')
                ->with('flash', ['broken_item_block' => $player->broken_item_key]);
        }

        return $next($request);
    }
}
