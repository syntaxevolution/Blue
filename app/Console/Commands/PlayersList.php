<?php

namespace App\Console\Commands;

use App\Models\Player;
use Illuminate\Console\Command;

/**
 * Human-side mirror of BotsList. Lists real (non-bot) players with
 * the fields you actually care about when diagnosing who's active,
 * who's stockpiling, and who's about to get raided:
 *
 *   - identity:     id, name
 *   - progression:  drill tier, four stats, MDN tag
 *   - economy:      cash, barrels, intel, moves
 *   - geography:    current position, home base coords
 *   - safety:       immunity status + expiry
 *   - activity:     moves_updated_at as a last-touch proxy (regen
 *                   reconciles on every gameplay action, so this is
 *                   effectively "last seen doing anything in-world")
 *
 * `--mdn=` filters by MDN tag (case-sensitive match on mdns.tag). No
 * filter = all human players.
 */
class PlayersList extends Command
{
    protected $signature = 'players:list {--mdn=}';

    protected $description = 'List active human (non-bot) players.';

    public function handle(): int
    {
        $mdnTag = $this->option('mdn');

        $query = Player::query()
            ->with([
                'user:id,name,is_bot',
                'currentTile:id,x,y',
                'baseTile:id,x,y',
                'mdn:id,tag',
            ])
            ->whereHas('user', fn ($q) => $q->where('is_bot', false));

        if ($mdnTag !== null) {
            $query->whereHas('mdn', fn ($q) => $q->where('tag', $mdnTag));
        }

        $players = $query->orderBy('id')->get();

        if ($players->isEmpty()) {
            $this->warn('No human players.');

            return self::SUCCESS;
        }

        $rows = $players->map(fn (Player $p) => [
            'id' => $p->id,
            'name' => $p->user?->name ?? '—',
            'drill_tier' => 'T'.(int) $p->drill_tier,
            'stats' => $this->formatStats($p),
            'cash' => number_format((float) $p->akzar_cash, 2),
            'barrels' => $p->oil_barrels,
            'intel' => $p->intel,
            'moves' => $p->moves_current,
            'pos' => $p->currentTile ? "({$p->currentTile->x},{$p->currentTile->y})" : '—',
            'base' => $p->baseTile ? "({$p->baseTile->x},{$p->baseTile->y})" : '—',
            'mdn' => $p->mdn?->tag ? "[{$p->mdn->tag}]" : '—',
            'imm' => $this->formatImmunity($p),
            'last_seen' => $p->moves_updated_at?->diffForHumans() ?? 'never',
        ])->all();

        $this->table(
            ['#', 'Name', 'DrT', 'S/F/St/Sc', 'Cash', 'Oil', 'Intel', 'Moves', 'Pos', 'Base', 'MDN', 'Imm', 'Last seen'],
            $rows,
        );
        $this->info("Total players: {$players->count()}");

        return self::SUCCESS;
    }

    /**
     * Compact four-stat line: strength/fortification/stealth/security.
     * Stat cap is 25 per ultraplan, so a single slash-joined cell stays
     * ≤11 characters wide even at max.
     */
    private function formatStats(Player $p): string
    {
        return implode('/', [
            (int) $p->strength,
            (int) $p->fortification,
            (int) $p->stealth,
            (int) $p->security,
        ]);
    }

    /**
     * Immunity cell: blank when not immune, otherwise a relative
     * countdown. Past timestamps shouldn't normally appear (immunity
     * is "valid if future") but we flag them with † for debugging in
     * case the scheduled cleanup ever drifts.
     */
    private function formatImmunity(Player $p): string
    {
        $expires = $p->immunity_expires_at;
        if ($expires === null) {
            return '';
        }
        if ($expires->isPast()) {
            return '† '.$expires->diffForHumans();
        }

        return '✓ '.$expires->diffForHumans();
    }
}
