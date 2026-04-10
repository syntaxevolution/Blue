<?php

namespace App\Domain\Player;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotTravelException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\World\FogOfWarService;
use App\Models\Player;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Move a player one tile in a cardinal direction.
 *
 * The gameplay contract:
 *   - Costs actions.travel.move_cost (default 1) moves
 *   - Must target an existing adjacent tile (edge of world is a hard stop)
 *   - Deducts moves, updates current_tile_id, and marks the new tile as
 *     discovered via FogOfWarService
 *
 * Everything runs inside a single DB::transaction with lockForUpdate on
 * the Player row so two concurrent travel requests can't double-spend
 * the same move budget.
 */
class TravelService
{
    private const DIRECTIONS = [
        'n' => [0, 1],
        's' => [0, -1],
        'e' => [1, 0],
        'w' => [-1, 0],
    ];

    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly MoveRegenService $moveRegen,
        private readonly FogOfWarService $fogOfWar,
    ) {}

    /**
     * Perform a travel action for the given player.
     *
     * @return Tile the new current tile
     */
    public function travel(int $playerId, string $direction): Tile
    {
        if (! isset(self::DIRECTIONS[$direction])) {
            throw CannotTravelException::invalidDirection($direction);
        }

        $cost = (int) $this->config->get('actions.travel.move_cost');
        [$dx, $dy] = self::DIRECTIONS[$direction];

        return DB::transaction(function () use ($playerId, $cost, $dx, $dy) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            // Bring the player's move budget up to date before the check.
            $this->moveRegen->reconcile($player);
            $player->refresh();

            if ($player->moves_current < $cost) {
                throw InsufficientMovesException::forAction('travel', $player->moves_current, $cost);
            }

            /** @var Tile $from */
            $from = Tile::query()->findOrFail($player->current_tile_id);

            $newX = $from->x + $dx;
            $newY = $from->y + $dy;

            /** @var Tile|null $destination */
            $destination = Tile::query()->where(['x' => $newX, 'y' => $newY])->first();

            if ($destination === null) {
                throw CannotTravelException::edgeOfWorld($newX, $newY);
            }

            $player->update([
                'moves_current' => $player->moves_current - $cost,
                'current_tile_id' => $destination->id,
            ]);

            $this->fogOfWar->markDiscovered($player->id, $destination->id);

            return $destination;
        });
    }
}
