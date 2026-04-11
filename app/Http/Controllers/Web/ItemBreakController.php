<?php

namespace App\Http\Controllers\Web;

use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Items\ItemBreakService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class ItemBreakController extends Controller
{
    public function __construct(
        private readonly ItemBreakService $itemBreak,
    ) {}

    public function repair(Request $request): RedirectResponse
    {
        $player = $request->user()->player;
        if ($player === null) {
            return Redirect::back()->withErrors(['item_break' => 'No player found — enter the map first.']);
        }

        try {
            $this->itemBreak->repair($player);
        } catch (CannotPurchaseException $e) {
            return Redirect::back()->withErrors(['item_break' => $e->getMessage()]);
        }

        return Redirect::back()->with('flash', [
            'item_repair_result' => 'Item repaired — you can continue.',
        ]);
    }

    public function abandon(Request $request): RedirectResponse
    {
        $player = $request->user()->player;
        if ($player === null) {
            return Redirect::back()->withErrors(['item_break' => 'No player found — enter the map first.']);
        }

        $this->itemBreak->abandon($player);

        return Redirect::back()->with('flash', [
            'item_abandon_result' => 'Item abandoned.',
        ]);
    }
}
