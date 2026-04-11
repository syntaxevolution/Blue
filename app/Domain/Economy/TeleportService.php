<?php

namespace App\Domain\Economy;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotPurchaseException;
use App\Domain\Exceptions\CannotTravelException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Player\MoveRegenService;
use App\Domain\World\FogOfWarService;
use App\Models\Player;
use App\Models\PlayerItem;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Teleporter: one-time general-store purchase (250k barrels by default),
 * each use charges an additional per-use cost (5k barrels by default).
 *
 * Validation order matters:
 *   1. Must own a teleporter.
 *   2. Destination tile must exist — if not, 422 with NO charge.
 *   3. Must have enough barrels to cover the per-use fee.
 *   4. Transaction: deduct fee, update current_tile_id, reveal fog.
 */
class TeleportService
{
    public const TELEPORTER_KEY = 'teleporter';

    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly FogOfWarService $fogOfWar,
        private readonly MoveRegenService $moveRegen,
    ) {}

    /**
     * Does the target tile exist? Used by the UI preview so we can warn
     * the user before they trigger the charged action.
     */
    public function tileExists(int $x, int $y): bool
    {
        return Tile::query()->where(['x' => $x, 'y' => $y])->exists();
    }

    public function playerOwnsTeleporter(Player $player): bool
    {
        return PlayerItem::query()
            ->where('player_id', $player->id)
            ->where('item_key', self::TELEPORTER_KEY)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->exists();
    }

    /**
     * Execute a teleport. Throws CannotTravelException / CannotPurchaseException
     * on any failure, and guarantees no partial state on the player row.
     *
     * @return Tile the new current tile
     */
    public function teleport(int $playerId, int $x, int $y): Tile
    {
        return DB::transaction(function () use ($playerId, $x, $y) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            if (! $this->playerOwnsTeleporter($player)) {
                throw CannotTravelException::teleporterNotOwned();
            }

            $this->moveRegen->reconcile($player);
            $player->refresh();

            $moveCost = (int) $this->config->get('actions.teleport.move_cost');
            if ($moveCost > 0 && (int) $player->moves_current < $moveCost) {
                throw InsufficientMovesException::forAction('teleport', (int) $player->moves_current, $moveCost);
            }

            /** @var Tile|null $destination */
            $destination = Tile::query()->where(['x' => $x, 'y' => $y])->first();
            if ($destination === null) {
                // No charge is applied before this point — the user gets
                // a clean rejection and their barrels and moves are untouched.
                throw CannotTravelException::edgeOfWorld($x, $y);
            }

            $barrelCost = (int) $this->config->get('teleport.cost_barrels');

            if ((int) $player->oil_barrels < $barrelCost) {
                throw CannotPurchaseException::insufficientBarrels((int) $player->oil_barrels, $barrelCost);
            }

            $player->update([
                'oil_barrels' => (int) $player->oil_barrels - $barrelCost,
                'moves_current' => (int) $player->moves_current - $moveCost,
                'current_tile_id' => $destination->id,
            ]);

            $this->fogOfWar->markDiscovered($player->id, $destination->id);

            return $destination;
        });
    }
}
