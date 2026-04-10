<?php

namespace App\Domain\Config;

use Illuminate\Support\Facades\Facade;

/**
 * Static facade for GameConfigResolver.
 *
 * Usage in game code:
 *
 *     use App\Domain\Config\GameConfig;
 *
 *     $cap  = GameConfig::get('stats.hard_cap');
 *     $cost = GameConfig::get('actions.attack.move_cost');
 *
 * NEVER hardcode balance values — always go through this facade. The
 * accessor resolves to the singleton-bound GameConfigResolver, which
 * checks the in-memory cache → game_settings DB overrides → config/game.php.
 *
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void  set(string $key, mixed $value, ?int $userId = null)
 * @method static void  flush()
 */
class GameConfig extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GameConfigResolver::class;
    }
}
