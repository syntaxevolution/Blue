<?php

namespace App\Jobs;

use App\Domain\Casino\RouletteService;
use App\Events\Casino\RouletteResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ResolveRouletteRound implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $tableId,
        public readonly int $roundNumber,
    ) {}

    public function handle(RouletteService $roulette): void
    {
        $result = $roulette->resolveSpin($this->tableId, $this->roundNumber);

        if ($result['number'] === -1) {
            Log::info('roulette.stale_job', [
                'table_id' => $this->tableId,
                'round_number' => $this->roundNumber,
            ]);

            return;
        }

        RouletteResult::dispatch(
            $this->tableId,
            $this->roundNumber,
            $result['number'],
            $result['color'],
            $result['payouts'],
        );
    }
}
