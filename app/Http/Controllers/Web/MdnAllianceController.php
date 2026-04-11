<?php

namespace App\Http\Controllers\Web;

use App\Domain\Exceptions\MdnException;
use App\Domain\Mdn\MdnAllianceService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MdnAllianceController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly MdnAllianceService $alliances,
    ) {}

    public function store(Request $request, int $mdn): RedirectResponse
    {
        $data = $request->validate([
            'other_mdn_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->alliances->declare($player->id, (int) $data['other_mdn_id']);
        } catch (MdnException $e) {
            return redirect()->route('mdn.show', $mdn)->withErrors(['alliance' => $e->getMessage()]);
        }

        return redirect()->route('mdn.show', $mdn)->with('status', 'Alliance declared.');
    }

    public function destroy(Request $request, int $mdn, int $alliance): RedirectResponse
    {
        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->alliances->revoke($player->id, $alliance);
        } catch (MdnException $e) {
            return redirect()->route('mdn.show', $mdn)->withErrors(['alliance' => $e->getMessage()]);
        }

        return redirect()->route('mdn.show', $mdn)->with('status', 'Alliance revoked.');
    }
}
