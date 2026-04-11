<?php

namespace App\Console\Commands;

use App\Domain\Config\GameConfigResolver;
use App\Domain\World\WorldService;
use Illuminate\Console\Command;

/**
 * Admin / scheduler entrypoint for automatic world growth.
 *
 * Delegates to WorldService::expandWorld, which handles the density
 * check, the kill-switch, the ring plan, and the transactional insert.
 * This command is a thin wrapper: it prints a stats table so both the
 * scheduler's log file and a live SSH run can see what actually
 * happened.
 *
 * Examples:
 *   php artisan world:grow            run the check and grow if needed
 *   php artisan world:grow --dry-run  report density/frontier without
 *                                     touching the database
 */
class WorldGrow extends Command
{
    protected $signature = 'world:grow
        {--dry-run : Report the density check and frontier without touching the DB}';

    protected $description = 'Grow the world by one integer ring if human player density has crossed the trigger.';

    public function handle(WorldService $world, GameConfigResolver $config): int
    {
        $enabled = (bool) $config->get('world.growth.enabled');
        $trigger = (float) $config->get('world.growth.trigger_players_per_tile');
        $density = $world->currentHumanPlayerDensity();

        if ((bool) $this->option('dry-run')) {
            $this->table(
                ['Enabled', 'Density', 'Trigger', 'Would grow?'],
                [[
                    $enabled ? 'yes' : 'no',
                    number_format($density, 5),
                    number_format($trigger, 5),
                    ($enabled && $density > $trigger) ? 'yes' : 'no',
                ]],
            );

            return self::SUCCESS;
        }

        $added = $world->expandWorld();

        if ($added === 0) {
            $this->info(sprintf(
                'No growth (enabled=%s, density=%.5f, trigger=%.5f).',
                $enabled ? 'true' : 'false',
                $density,
                $trigger,
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('World grew: %d new tiles added at the frontier.', $added));

        return self::SUCCESS;
    }
}
