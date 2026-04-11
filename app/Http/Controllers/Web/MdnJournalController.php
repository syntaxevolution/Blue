<?php

namespace App\Http\Controllers\Web;

use App\Domain\Exceptions\MdnException;
use App\Domain\Mdn\MdnJournalService;
use App\Domain\World\WorldService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MdnJournalController extends Controller
{
    public function __construct(
        private readonly WorldService $world,
        private readonly MdnJournalService $journal,
    ) {}

    public function store(Request $request, int $mdn): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
            'tile_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->journal->addEntry($player->id, $data['tile_id'] ?? null, $data['body']);
        } catch (MdnException $e) {
            return redirect()->route('mdn.show', $mdn)->withErrors(['journal' => $e->getMessage()]);
        }

        return redirect()->route('mdn.show', $mdn)->with('status', 'Journal entry added.');
    }

    public function vote(Request $request, int $mdn, int $entry): RedirectResponse
    {
        $data = $request->validate([
            'vote' => ['required', 'string', 'in:helpful,unhelpful'],
        ]);

        $user = $request->user();
        $player = $user->player ?? $this->world->spawnPlayer($user->id);

        try {
            $this->journal->vote($player->id, $entry, $data['vote']);
        } catch (MdnException $e) {
            return redirect()->route('mdn.show', $mdn)->withErrors(['journal' => $e->getMessage()]);
        }

        return redirect()->route('mdn.show', $mdn);
    }
}
