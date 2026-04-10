<?php

namespace App\Http\Middleware;

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
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                // Lazy-loaded via closure so only pages that actually
                // render the gear modal pay for the query.
                'owned_items' => function () use ($request) {
                    $user = $request->user();
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
                        ])
                        ->map(fn ($row) => [
                            'key' => $row->key,
                            'name' => $row->name,
                            'description' => $row->description,
                            'post_type' => $row->post_type,
                            'quantity' => (int) $row->quantity,
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
            ],
        ];
    }
}
