<?php

namespace App\Domain\Casino;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CasinoException;
use App\Models\CasinoSession;
use App\Models\Player;
use App\Models\Tile;
use Illuminate\Support\Facades\DB;

class CasinoService
{
    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    /**
     * Pay the entry fee and create a casino session.
     *
     * @return array{session_id: int, expires_at: string, fee_charged: int}
     */
    public function enterCasino(int $playerId): array
    {
        if (! (bool) $this->config->get('casino.enabled')) {
            throw CasinoException::casinoDisabled();
        }

        return DB::transaction(function () use ($playerId) {
            /** @var Player $player */
            $player = Player::query()->lockForUpdate()->findOrFail($playerId);

            // Lock the tile row too — tile.type is effectively immutable
            // post-generation, but locking protects against concurrent
            // world growth / retrofit commands and any future world
            // service that could mutate the tile.
            $tile = Tile::query()->lockForUpdate()->find($player->current_tile_id);
            if ($tile === null || $tile->type !== 'casino') {
                throw CasinoException::notOnCasinoTile($tile?->type ?? 'unknown');
            }

            $existing = $this->activeSession($playerId);
            if ($existing !== null) {
                return [
                    'session_id' => $existing->id,
                    'expires_at' => $existing->expires_at->toIso8601String(),
                    'fee_charged' => 0,
                ];
            }

            $fee = (int) $this->config->get('casino.entry_fee_barrels');

            if ($player->oil_barrels < $fee) {
                throw CasinoException::insufficientBarrels($player->oil_barrels, $fee);
            }

            $player->update([
                'oil_barrels' => $player->oil_barrels - $fee,
            ]);

            $durationMinutes = (int) $this->config->get('casino.session_duration_minutes');

            $session = CasinoSession::create([
                'player_id' => $playerId,
                'entered_at' => now(),
                'expires_at' => now()->addMinutes($durationMinutes),
                'fee_amount' => $fee,
            ]);

            return [
                'session_id' => $session->id,
                'expires_at' => $session->expires_at->toIso8601String(),
                'fee_charged' => $fee,
            ];
        });
    }

    public function hasActiveSession(int $playerId): bool
    {
        return $this->activeSession($playerId) !== null;
    }

    public function activeSession(int $playerId): ?CasinoSession
    {
        return CasinoSession::query()
            ->where('player_id', $playerId)
            ->where('expires_at', '>', now())
            ->orderByDesc('expires_at')
            ->first();
    }

    public function requireActiveSession(int $playerId): CasinoSession
    {
        $session = $this->activeSession($playerId);

        if ($session === null) {
            throw CasinoException::noActiveSession();
        }

        return $session;
    }
}
