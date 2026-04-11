<?php

namespace App\Domain\Player;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Economy\TransportService;
use App\Domain\Exceptions\CannotTravelException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\World\FogOfWarService;
use App\Models\Player;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Multi-tile movement for non-walking transports.
 *
 * Contract:
 *   - Each button press costs a flat 1 move from the daily budget
 *     (actions.travel.move_cost) regardless of distance — the whole
 *     point of transport is efficiency.
 *   - Fuel is deducted per-press, not per-tile (user spec).
 *   - Every intermediate tile AND the destination must exist. If any
 *     is missing (edge of world) the trip fails atomically before any
 *     move/fuel/position is deducted.
 *   - Fog-of-war reveal rules:
 *       * airplane (flag: reveal_path) — reveals every intermediate tile
 *       * sand_runner (flag: reveal_cardinal_neighbours) — reveals the 4
 *         cardinal neighbours of the destination tile in addition to the
 *         destination itself
 *       * all others — destination only
 *   - Walking (1 space, 0 fuel) goes through the existing TravelService
 *     unchanged — this class is only engaged when active_transport != walking.
 */
class TransportMovementService
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
        private readonly TransportService $transport,
    ) {}

    /**
     * Perform a multi-tile move in the given direction using the player's
     * currently active transport.
     *
     * @return Tile the new current tile
     */
    public function travel(int $playerId, string $direction): Tile
    {
        if (! isset(self::DIRECTIONS[$direction])) {
            throw CannotTravelException::invalidDirection($direction);
        }

        [$dx, $dy] = self::DIRECTIONS[$direction];
        $moveCost = (int) $this->config->get('actions.travel.move_cost');

        return DB::transaction(function () use ($playerId, $dx, $dy, $moveCost) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            $this->moveRegen->reconcile($player);
            $player->refresh();

            $transportKey = (string) ($player->active_transport ?? TransportService::DEFAULT);
            $cfg = $this->transport->configFor($transportKey);
            if ($cfg === null) {
                throw CannotTravelException::unknownTransport($transportKey);
            }

            // Walking falls through to the simple 1-space move: 1 tile,
            // 0 fuel, 1 move. Same shape as TravelService but reused here
            // so the controller can always delegate to this service.
            $spaces = max(1, (int) $cfg['spaces']);
            $fuel = max(0, (int) $cfg['fuel']);
            $flags = (array) $cfg['flags'];

            if ($player->moves_current < $moveCost) {
                throw InsufficientMovesException::forAction('travel', $player->moves_current, $moveCost);
            }

            if ($fuel > 0 && (int) $player->oil_barrels < $fuel) {
                throw CannotTravelException::insufficientFuel((int) $player->oil_barrels, $fuel);
            }

            /** @var Tile $from */
            $from = Tile::query()->findOrFail($player->current_tile_id);

            // Walk the full trajectory; verify every tile exists.
            /** @var list<Tile> $path */
            $path = [];
            for ($step = 1; $step <= $spaces; $step++) {
                $nx = $from->x + $dx * $step;
                $ny = $from->y + $dy * $step;

                /** @var Tile|null $tile */
                $tile = Tile::query()->where(['x' => $nx, 'y' => $ny])->first();

                if ($tile === null) {
                    throw CannotTravelException::edgeOfWorld($nx, $ny);
                }

                $path[] = $tile;
            }

            $destination = $path[count($path) - 1];

            // Deduct move + fuel + update position.
            $updates = [
                'moves_current' => (int) $player->moves_current - $moveCost,
                'current_tile_id' => $destination->id,
            ];
            if ($fuel > 0) {
                $updates['oil_barrels'] = (int) $player->oil_barrels - $fuel;
            }
            $player->update($updates);

            // Fog reveal.
            $revealIds = [$destination->id];

            if (in_array('reveal_path', $flags, true)) {
                foreach ($path as $tile) {
                    $revealIds[] = $tile->id;
                }
            }

            if (in_array('reveal_cardinal_neighbours', $flags, true)) {
                $neighbourCoords = [
                    [$destination->x, $destination->y + 1],
                    [$destination->x, $destination->y - 1],
                    [$destination->x + 1, $destination->y],
                    [$destination->x - 1, $destination->y],
                ];
                $neighbours = Tile::query()
                    ->where(function ($q) use ($neighbourCoords) {
                        foreach ($neighbourCoords as [$nx, $ny]) {
                            $q->orWhere(fn ($q2) => $q2->where('x', $nx)->where('y', $ny));
                        }
                    })
                    ->pluck('id')
                    ->all();
                $revealIds = array_merge($revealIds, $neighbours);
            }

            $this->fogOfWar->markDiscoveredMany($player->id, array_values(array_unique($revealIds)));

            return $destination;
        });
    }
}
