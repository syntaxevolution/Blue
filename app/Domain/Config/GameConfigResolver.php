<?php

namespace App\Domain\Config;

use App\Models\GameSetting;
use App\Models\GameSettingAudit;
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
     * Persist an override to the game_settings table with an audit entry,
     * and invalidate the in-memory cache so subsequent reads pick it up.
     *
     * Runs in a DB transaction so the setting write and the audit row
     * commit together. If no DB is reachable (unit tests, fresh install),
     * the override is staged in memory only so callers still see the new
     * value for the duration of the request.
     */
    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $previous = $this->dbOverrides[$key]
            ?? $this->config->get('game.'.$key);

        try {
            DB::transaction(function () use ($key, $value, $userId, $previous) {
                GameSetting::updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => $value,
                        'type' => $this->detectType($value),
                        'updated_by_user_id' => $userId,
                    ],
                );

                GameSettingAudit::create([
                    'key' => $key,
                    'old_value' => $previous,
                    'new_value' => $value,
                    'changed_by_user_id' => $userId,
                    'changed_at' => now(),
                ]);
            });
        } catch (Throwable) {
            // Fall through — DB unavailable. Override will live in memory
            // for this request only, which is acceptable during tests/bootstrap.
        }

        $this->dbOverrides[$key] = $value;
        unset($this->memoryCache[$key]);
    }

    /**
     * Drop all caches. Called after bulk admin-panel edits or at the start
     * of a test that needs a clean slate.
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
            foreach (GameSetting::all(['key', 'value']) as $setting) {
                $this->dbOverrides[$setting->key] = $setting->value;
            }
        } catch (Throwable) {
            // Table absent or DB unreachable — fall back to static config.
        }
    }

    private function detectType(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_bool($value) => 'bool',
            is_array($value) => 'array',
            default => 'string',
        };
    }
}
