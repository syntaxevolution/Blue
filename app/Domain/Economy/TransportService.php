<?php

namespace App\Domain\Economy;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CannotTravelException;
use App\Models\Player;
use App\Models\PlayerItem;

/**
 * Manages transport mode selection and catalogue lookup.
 *
 * Walking is the implicit default — every player always "owns" it.
 * Every other mode is a general-store purchase stored in player_items
 * and active per-player via the players.active_transport column.
 */
class TransportService
{
    public const DEFAULT = 'walking';

    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    /**
     * Switch the player's active transport. Throws if the player
     * doesn't own the requested transport (or it's broken).
     */
    public function switchTo(Player $player, string $transport): void
    {
        if ($transport === self::DEFAULT) {
            $player->forceFill(['active_transport' => self::DEFAULT])->save();

            return;
        }

        if (! $this->isKnown($transport)) {
            throw CannotTravelException::unknownTransport($transport);
        }

        $owned = PlayerItem::query()
            ->where('player_id', $player->id)
            ->where('item_key', $transport)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->exists();

        if (! $owned) {
            throw CannotTravelException::transportNotOwned($transport);
        }

        $player->forceFill(['active_transport' => $transport])->save();
    }

    /**
     * Config for a given transport mode, including the implicit
     * walking default. Returns null for unknown names.
     *
     * @return array{cost_barrels:int,spaces:int,fuel:int,flags:array<int,string>}|null
     */
    public function configFor(string $transport): ?array
    {
        if ($transport === self::DEFAULT) {
            return [
                'cost_barrels' => 0,
                'spaces' => 1,
                'fuel' => 0,
                'flags' => [],
            ];
        }

        $cfg = $this->config->get('general_store.transport.'.$transport);
        if (! is_array($cfg)) {
            return null;
        }

        return [
            'cost_barrels' => (int) ($cfg['cost_barrels'] ?? 0),
            'spaces' => (int) ($cfg['spaces'] ?? 1),
            'fuel' => (int) ($cfg['fuel'] ?? 0),
            'flags' => (array) ($cfg['flags'] ?? []),
        ];
    }

    public function isKnown(string $transport): bool
    {
        if ($transport === self::DEFAULT) {
            return true;
        }

        return is_array($this->config->get('general_store.transport.'.$transport));
    }

    /**
     * Full list of transport keys from config (walking first, then purchasable).
     *
     * @return list<string>
     */
    public function allKeys(): array
    {
        $all = (array) $this->config->get('general_store.transport');
        $keys = array_keys($all);

        return array_values(array_merge([self::DEFAULT], $keys));
    }

    /**
     * Which transports does the player actually own (walking + any
     * active player_items rows matching known transport keys).
     *
     * @return list<string>
     */
    public function ownedKeys(Player $player): array
    {
        $known = array_keys((array) $this->config->get('general_store.transport'));

        $owned = PlayerItem::query()
            ->where('player_id', $player->id)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->whereIn('item_key', $known)
            ->pluck('item_key')
            ->all();

        return array_values(array_merge([self::DEFAULT], $owned));
    }
}
