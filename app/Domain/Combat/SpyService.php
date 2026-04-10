<?php

namespace App\Domain\Combat;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CannotSpyException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Player\MoveRegenService;
use App\Models\Player;
use App\Models\SpyAttempt;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Spy action — scout another player's base.
 *
 * Phase 3 MVP: single-depth spying. A successful spy unlocks attack
 * rights on the target for the next combat.spy_decay_hours window
 * (default 24h) and grants intel.spy_depth_1 intel to the spy.
 *
 * Multi-depth spies (2 grants cash+fort visibility, 3 grants guaranteed
 * escape) are spec'd in gameplay-ultraplan §8 but the richer depth
 * flow + UI come in a later pass.
 *
 * Success is rolled from stealth vs security: clamp to a reasonable
 * band so a zero-stealth spy still has some chance and a max-stealth
 * spy isn't guaranteed.
 */
class SpyService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly MoveRegenService $moveRegen,
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     intel_gained: int,
     *     spy_id: int,
     *     moves_remaining: int,
     * }
     */
    public function spy(int $spyPlayerId): array
    {
        $cost = (int) $this->config->get('actions.spy.move_cost');

        return DB::transaction(function () use ($spyPlayerId, $cost) {
            /** @var Player $spy */
            $spy = Player::query()->lockForUpdate()->findOrFail($spyPlayerId);

            $this->moveRegen->reconcile($spy);
            $spy->refresh();

            if ($spy->moves_current < $cost) {
                throw InsufficientMovesException::forAction('spy', $spy->moves_current, $cost);
            }

            /** @var Tile $tile */
            $tile = Tile::query()->findOrFail($spy->current_tile_id);
            if ($tile->type !== 'base') {
                throw CannotSpyException::notOnABase($tile->type);
            }

            if ($tile->id === $spy->base_tile_id) {
                throw CannotSpyException::ownBase();
            }

            /** @var Player|null $target */
            $target = Player::query()->where('base_tile_id', $tile->id)->first();
            if ($target === null) {
                throw CannotSpyException::targetNotFound();
            }

            if ($target->immunity_expires_at !== null && $target->immunity_expires_at->isFuture()) {
                throw CannotSpyException::targetImmune();
            }

            // Roll success. A zero-stealth spy still has ~30% baseline.
            // A max-stealth spy against zero security still caps at ~95%.
            $eventKey = 'spy-'.$spy->id.'-'.$target->id.'-'.now()->timestamp;
            $roll = $this->rng->rollFloat('combat.spy', $eventKey, 0.0, 1.0);

            $stealth = (int) $spy->stealth;
            $security = (int) $target->security;
            $baseChance = 0.3 + 0.05 * max(0, $stealth - $security);
            $successChance = max(0.1, min(0.95, $baseChance));
            $success = $roll < $successChance;

            $intelGained = 0;
            if ($success) {
                $intelGained = (int) $this->config->get('intel.earn.spy_depth_1');
            }

            /** @var SpyAttempt $spyRow */
            $spyRow = SpyAttempt::create([
                'spy_player_id' => $spy->id,
                'target_player_id' => $target->id,
                'target_base_tile_id' => $tile->id,
                'success' => $success,
                'detected' => false,
                'rng_seed' => crc32($eventKey),
                'rng_output' => (string) $roll,
                'created_at' => now(),
            ]);

            $updates = ['moves_current' => $spy->moves_current - $cost];
            if ($intelGained > 0) {
                $updates['intel'] = $spy->intel + $intelGained;
            }
            $spy->update($updates);

            return [
                'outcome' => $success ? 'success' : 'failure',
                'intel_gained' => $intelGained,
                'spy_id' => $spyRow->id,
                'moves_remaining' => $spy->moves_current - $cost,
            ];
        });
    }
}
