<?php

namespace App\Domain\Drilling;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Exceptions\CannotDrillException;
use App\Domain\Exceptions\InsufficientMovesException;
use App\Domain\Player\MoveRegenService;
use App\Models\DrillPoint;
use App\Models\OilField;
use App\Models\Player;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

/**
 * Drill a single point in the 5×5 sub-grid of the oil field a player
 * is standing on.
 *
 * The gameplay contract:
 *   - Player must be on a tile of type 'oil_field'
 *   - Costs actions.drill.move_cost moves
 *   - (grid_x, grid_y) must be inside 0..4
 *   - The target point must not already be drilled_at (depleted)
 *   - Yield is rolled via RngService against drilling.yields[quality]
 *     and multiplied by the player's drill tier yield_multiplier
 *   - Barrels land in the player's oil_barrels balance
 *   - The point is marked drilled_at=now() and becomes unusable until
 *     OilFieldRegenJob resets it at the next regen window
 *
 * Everything runs inside a DB::transaction with lockForUpdate on the
 * Player row and a second lock on the drill point so simultaneous
 * requests cannot double-drill the same cell.
 */
class DrillService
{
    public function __construct(
        private readonly GameConfigResolver $config,
        private readonly RngService $rng,
        private readonly MoveRegenService $moveRegen,
    ) {}

    /**
     * @return array{
     *     quality: string,
     *     barrels: int,
     *     new_balance: int,
     *     moves_remaining: int,
     * }
     */
    public function drill(int $playerId, int $gridX, int $gridY): array
    {
        if ($gridX < 0 || $gridX > 4 || $gridY < 0 || $gridY > 4) {
            throw CannotDrillException::outOfRange($gridX, $gridY);
        }

        $cost = (int) $this->config->get('actions.drill.move_cost');

        return DB::transaction(function () use ($playerId, $gridX, $gridY, $cost) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            $this->moveRegen->reconcile($player);
            $player->refresh();

            if ($player->moves_current < $cost) {
                throw InsufficientMovesException::forAction('drill', $player->moves_current, $cost);
            }

            /** @var Tile $tile */
            $tile = Tile::query()->findOrFail($player->current_tile_id);
            if ($tile->type !== 'oil_field') {
                throw CannotDrillException::notAnOilField($tile->type);
            }

            /** @var OilField $field */
            $field = OilField::query()
                ->where('tile_id', $tile->id)
                ->firstOrFail();

            /** @var DrillPoint $point */
            $point = DrillPoint::query()
                ->lockForUpdate()
                ->where('oil_field_id', $field->id)
                ->where('grid_x', $gridX)
                ->where('grid_y', $gridY)
                ->firstOrFail();

            if ($point->drilled_at !== null) {
                throw CannotDrillException::pointDepleted($gridX, $gridY);
            }

            $barrels = $this->computeYield($point->quality, $player->drill_tier);

            $point->update(['drilled_at' => now()]);

            $player->update([
                'moves_current' => $player->moves_current - $cost,
                'oil_barrels' => $player->oil_barrels + $barrels,
            ]);

            return [
                'quality' => $point->quality,
                'barrels' => $barrels,
                'new_balance' => $player->oil_barrels + $barrels,
                'moves_remaining' => $player->moves_current - $cost,
            ];
        });
    }

    /**
     * Roll the barrel yield for a drill point of the given quality,
     * then scale by the player's drill tier yield_multiplier.
     */
    private function computeYield(string $quality, int $drillTier): int
    {
        /** @var array{0:int,1:int}|null $band */
        $band = $this->config->get("drilling.yields.{$quality}");

        if (! is_array($band) || count($band) < 2) {
            return 0;
        }

        [$min, $max] = [(int) $band[0], (int) $band[1]];

        if ($min === 0 && $max === 0) {
            return 0;
        }

        $base = $this->rng->rollInt(
            "drilling.yield.{$quality}",
            uniqid('drill_', true),
            $min,
            $max,
        );

        $multiplier = (float) $this->config->get("drilling.equipment.{$drillTier}.yield_multiplier", 1.0);

        return (int) floor($base * $multiplier);
    }
}
