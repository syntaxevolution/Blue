<?php

namespace App\Domain\Config;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Resolves game configuration values from (in order):
 *
 *   1. In-memory per-request cache
 *   2. game_settings DB table (lazy-loaded once per request)
 *   3. config/game.php static defaults
 *
 * A Redis caching layer will be slotted between #1 and #2 in a later pass;
 * the API stays the same.
 *
 * Unknown keys return the provided $default (or null). A strict mode could
 * be added later to throw during local/testing envs.
 */
class GameConfigResolver
{
    /** @var array<string,mixed> */
    private array $memoryCache = [];

    /** @var array<string,mixed> */
    private array $dbOverrides = [];

    private bool $dbLoaded = false;

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->memoryCache)) {
            return $this->memoryCache[$key];
        }

        $this->ensureDbLoaded();

        if (array_key_exists($key, $this->dbOverrides)) {
            return $this->memoryCache[$key] = $this->dbOverrides[$key];
        }

        $static = $this->config->get('game.'.$key);

        return $this->memoryCache[$key] = $static ?? $default;
    }

    /**
     * Persist an override to the game_settings table and invalidate the in-memory cache.
     * Phase B fleshes this out with audit-log writes; for now this is a stub.
     */
    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        // Wired in Phase B once the game_settings migration exists.
        // Intentionally minimal here so Phase A stays pure-PHP testable.
        $this->dbOverrides[$key] = $value;
        unset($this->memoryCache[$key]);
    }

    /**
     * Drop all caches. Called after admin-panel edits.
     */
    public function flush(): void
    {
        $this->memoryCache = [];
        $this->dbOverrides = [];
        $this->dbLoaded = false;
    }

    /**
     * Lazily load all DB overrides on first access.
     * Silently tolerates a missing table (fresh install, unit tests, etc).
     */
    private function ensureDbLoaded(): void
    {
        if ($this->dbLoaded) {
            return;
        }

        $this->dbLoaded = true;

        try {
            $rows = DB::table('game_settings')->get(['key', 'value']);

            foreach ($rows as $row) {
                $this->dbOverrides[$row->key] = json_decode($row->value, true);
            }
        } catch (Throwable) {
            // Table absent or DB unreachable — fall back to static config.
        }
    }
}
