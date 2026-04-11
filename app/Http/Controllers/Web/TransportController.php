<?php

namespace App\Http\Controllers\Web;

use App\Domain\Economy\TransportService;
use App\Domain\Exceptions\CannotTravelException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class TransportController extends Controller
{
    public function __construct(
        private readonly TransportService $transport,
    ) {}

    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'transport' => ['required', 'string'],
        ]);

        $player = $request->user()->player;

        try {
            $this->transport->switchTo($player, $validated['transport']);
        } catch (CannotTravelException $e) {
            return Redirect::back()->withErrors(['transport' => $e->getMessage()]);
        }

        return Redirect::back()->with('flash', [
            'transport_switched' => $validated['transport'],
        ]);
    }
}
