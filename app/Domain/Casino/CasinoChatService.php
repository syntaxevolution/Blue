<?php

namespace App\Domain\Casino;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Exceptions\CasinoException;
use App\Models\CasinoChatMessage;
use Illuminate\Support\Collection;

class CasinoChatService
{
    public function __construct(
        private readonly GameConfigResolver $config,
    ) {}

    public function sendMessage(int $playerId, int $tableId, string $message): CasinoChatMessage
    {
        if (! (bool) $this->config->get('casino.chat.enabled', true)) {
            throw CasinoException::invalidAction('chat is disabled');
        }

        $maxLength = (int) $this->config->get('casino.chat.max_message_length', 200);
        $message = mb_substr(trim($message), 0, $maxLength);

        if ($message === '') {
            throw CasinoException::invalidAction('empty message');
        }

        $rateLimit = (int) $this->config->get('casino.chat.rate_limit_per_minute', 10);
        $recentCount = CasinoChatMessage::query()
            ->where('player_id', $playerId)
            ->where('created_at', '>=', now()->subMinute())
            ->count();

        if ($recentCount >= $rateLimit) {
            throw CasinoException::invalidAction('chat rate limit reached');
        }

        return CasinoChatMessage::create([
            'casino_table_id' => $tableId,
            'player_id' => $playerId,
            'message' => $message,
            'created_at' => now(),
        ]);
    }

    public function recentMessages(int $tableId, int $limit = 50): Collection
    {
        $limit = min($limit, (int) $this->config->get('casino.chat.history_load_count', 50));

        return CasinoChatMessage::query()
            ->where('casino_table_id', $tableId)
            ->with('player:id,user_id', 'player.user:id,name')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (CasinoChatMessage $m) => [
                'id' => $m->id,
                'username' => $m->player?->user?->name ?? 'Unknown',
                'message' => $m->message,
                'created_at' => $m->created_at->toIso8601String(),
            ]);
    }
}
