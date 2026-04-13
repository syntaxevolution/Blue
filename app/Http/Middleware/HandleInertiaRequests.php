<?php

namespace App\Http\Middleware;

use App\Domain\Combat\AttackLogService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Notifications\ActivityLogService;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $player = $user?->player;

        // Enrich the broken-item context so the frontend modal can show
        // the item name, the repair cost, and whether the player can
        // afford it — without needing a second HTTP round-trip.
        $brokenItem = null;
        if ($player !== null && $player->broken_item_key !== null) {
            $item = Item::query()->where('key', $player->broken_item_key)->first();
            if ($item !== null) {
                $repairPct = (float) app(GameConfigResolver::class)->get('items.break.repair_cost_pct');
                $brokenItem = [
                    'key' => $item->key,
                    'name' => $item->name,
                    'repair_cost_barrels' => (int) ceil((float) $item->price_barrels * $repairPct),
                    'player_barrels' => (int) $player->oil_barrels,
                ];
            }
        }

        // Eagerly evaluate both unread counts so the navbar badges
        // refresh on every Inertia navigation (including the redirect
        // back from /map/move). Closures in shared props can be
        // skipped during partial reloads; plain values always ride
        // along, which is what we want for the always-visible navbar.
        $unreadActivityCount = $user !== null
            ? app(ActivityLogService::class)->unreadCount((int) $user->id)
            : 0;

        $unreadHostilityCount = $player !== null
            ? app(AttackLogService::class)->unreadCount($player)
            : 0;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'requires_username_claim' => $user !== null && ! $user->hasClaimedUsername(),
                'email_verified' => $user !== null && $user->hasVerifiedEmail(),
                'broken_item_key' => $player?->broken_item_key,
                'broken_item' => $brokenItem,
                'active_transport' => $player?->active_transport ?? 'walking',
                'unread_activity_count' => $unreadActivityCount,
                'unread_hostility_count' => $unreadHostilityCount,
                // Lazy-loaded via closure so only pages that actually
                // render the gear modal pay for the query.
                'owned_items' => function () use ($user) {
                    if ($user === null || $user->player === null) {
                        return [];
                    }

                    return DB::table('player_items')
                        ->where('player_items.player_id', $user->player->id)
                        ->join('items_catalog', 'items_catalog.key', '=', 'player_items.item_key')
                        ->orderBy('items_catalog.post_type')
                        ->orderBy('items_catalog.sort_order')
                        ->get([
                            'items_catalog.key',
                            'items_catalog.name',
                            'items_catalog.description',
                            'items_catalog.post_type',
                            'items_catalog.effects',
                            'player_items.quantity',
                            'player_items.status',
                        ])
                        ->map(fn ($row) => [
                            'key' => $row->key,
                            'name' => $row->name,
                            'description' => $row->description,
                            'post_type' => $row->post_type,
                            'quantity' => (int) $row->quantity,
                            'status' => (string) $row->status,
                            'effects' => $row->effects ? json_decode($row->effects, true) : null,
                        ])
                        ->all();
                },
            ],
            'flash' => [
                'drill_result' => fn () => $request->session()->get('drill_result'),
                'purchase_result' => fn () => $request->session()->get('purchase_result'),
                'spy_result' => fn () => $request->session()->get('spy_result'),
                'attack_result' => fn () => $request->session()->get('attack_result'),
                'teleport_result' => fn () => $request->session()->get('teleport_result'),
                'transport_switched' => fn () => $request->session()->get('transport_switched'),
                'item_repair_result' => fn () => $request->session()->get('item_repair_result'),
                'item_abandon_result' => fn () => $request->session()->get('item_abandon_result'),
                'username_claimed' => fn () => $request->session()->get('username_claimed'),
                'casino_entered' => fn () => $request->session()->get('casino_entered'),
                'spin_result' => fn () => $request->session()->get('spin_result'),
                'roulette_bet' => fn () => $request->session()->get('roulette_bet'),
                'blackjack_result' => fn () => $request->session()->get('blackjack_result'),
                'holdem_result' => fn () => $request->session()->get('holdem_result'),
            ],
            // Broadcast connection details for the Echo JS client.
            'reverb' => [
                'app_key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port', 8080),
                'scheme' => config('broadcasting.connections.reverb.options.scheme', 'http'),
            ],
            // Game-config values the frontend needs for UI display.
            // Keeps the client honest with live admin tuning.
            'game' => [
                'teleport_cost_barrels' => (int) app(GameConfigResolver::class)->get('teleport.cost_barrels'),
                'teleport_purchase_cost_barrels' => (int) app(GameConfigResolver::class)->get('teleport.purchase_cost_barrels'),
                'casino_enabled' => (bool) app(GameConfigResolver::class)->get('casino.enabled'),
                'casino_entry_fee_barrels' => (int) app(GameConfigResolver::class)->get('casino.entry_fee_barrels'),
                'holdem_turn_seconds' => (int) app(GameConfigResolver::class)->get('casino.holdem.turn_timer_seconds'),
                'holdem_rake_pct' => (float) app(GameConfigResolver::class)->get('casino.holdem.rake_pct'),
                'slots_min_interval_seconds' => (int) app(GameConfigResolver::class)->get('casino.slots.min_interval_seconds', 1),
            ],
        ];
    }
}
