<?php

namespace App\Jobs;

use App\Domain\Casino\HoldemService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class HoldemTurnTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $tableId,
        public readonly int $playerId,
        public readonly int $roundNumber,
    ) {}

    public function handle(HoldemService $holdem): void
    {
        $holdem->handleTimeout($this->tableId, $this->playerId, $this->roundNumber);
    }
}
