<?php

namespace App\Providers;

use App\Domain\Combat\AttackLogService;
use App\Domain\Combat\AttackService;
use App\Domain\Combat\CombatFormula;
use App\Domain\Combat\SpyService;
use App\Domain\Combat\TileCombatEligibilityService;
use App\Domain\Combat\TileCombatService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Drilling\DrillService;
use App\Domain\Drilling\OilFieldRegenService;
use App\Domain\Economy\ExtraMovesService;
use App\Domain\Economy\ShopService;
use App\Domain\Economy\TeleportService;
use App\Domain\Economy\TransportService;
use App\Domain\Bot\BotDecisionService;
use App\Domain\Bot\BotSpawnService;
use App\Domain\Casino\BlackjackService;
use App\Domain\Casino\CasinoChatService;
use App\Domain\Casino\CasinoService;
use App\Domain\Casino\CasinoTableManager;
use App\Domain\Casino\HandEvaluator;
use App\Domain\Casino\HoldemService;
use App\Domain\Casino\RouletteService;
use App\Domain\Casino\SlotMachineService;
use App\Domain\Items\ItemBreakService;
use App\Domain\Mdn\MdnAllianceService;
use App\Domain\Mdn\MdnJournalService;
use App\Domain\Mdn\MdnService;
use App\Domain\Items\PassiveBonusService;
use App\Domain\Items\StatOverflowService;
use App\Domain\Leaderboard\LeaderboardService;
use App\Domain\Notifications\ActivityLogService;
use App\Domain\Player\AtlasService;
use App\Domain\Player\MapStateBuilder;
use App\Domain\Player\MoveRegenService;
use App\Domain\Player\TransportMovementService;
use App\Domain\Player\TravelService;
use App\Domain\World\FogOfWarService;
use App\Domain\World\WorldService;
use App\Events\BaseUnderAttack;
use App\Events\MdnEvent;
use App\Events\RaidCompleted;
use App\Events\SpyDetected;
use App\Events\TileCombatResolved;
use App\Listeners\RecordActivityLog;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GameConfigResolver::class);
        $this->app->singleton(RngService::class);
        $this->app->singleton(FogOfWarService::class);
        $this->app->singleton(WorldService::class);
        $this->app->singleton(MoveRegenService::class);
        $this->app->singleton(TravelService::class);
        $this->app->singleton(MapStateBuilder::class);
        $this->app->singleton(DrillService::class);
        $this->app->singleton(OilFieldRegenService::class);
        $this->app->singleton(ShopService::class);
        $this->app->singleton(CombatFormula::class);
        $this->app->singleton(SpyService::class);
        $this->app->singleton(AttackService::class);
        $this->app->singleton(TileCombatEligibilityService::class);
        $this->app->singleton(TileCombatService::class);

        // Batch 1 additions
        $this->app->singleton(StatOverflowService::class);
        $this->app->singleton(ItemBreakService::class);
        $this->app->singleton(PassiveBonusService::class);
        $this->app->singleton(ExtraMovesService::class);
        $this->app->singleton(TransportService::class);
        $this->app->singleton(TransportMovementService::class);
        $this->app->singleton(TeleportService::class);
        $this->app->singleton(ActivityLogService::class);
        $this->app->singleton(AtlasService::class);
        $this->app->singleton(AttackLogService::class);

        // MDN (Phase 4 — social layer)
        $this->app->singleton(MdnService::class);
        $this->app->singleton(MdnAllianceService::class);
        $this->app->singleton(MdnJournalService::class);

        // Phase 5 (partial) — leaderboards
        $this->app->singleton(LeaderboardService::class);

        // Bot players (Improvements II — autonomous AI opponents)
        $this->app->singleton(BotSpawnService::class);
        $this->app->singleton(BotDecisionService::class);

        // Casino — Roughneck's Saloon (Phase C)
        $this->app->singleton(CasinoService::class);
        $this->app->singleton(CasinoTableManager::class);
        $this->app->singleton(SlotMachineService::class);
        $this->app->singleton(RouletteService::class);
        $this->app->singleton(BlackjackService::class);
        $this->app->singleton(HoldemService::class);
        $this->app->singleton(HandEvaluator::class);
        $this->app->singleton(CasinoChatService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Wire broadcast events to the activity log listener so every
        // toast also lands as a persistent row for offline players.
        Event::listen(BaseUnderAttack::class, [RecordActivityLog::class, 'handleBaseUnderAttack']);
        Event::listen(SpyDetected::class, [RecordActivityLog::class, 'handleSpyDetected']);
        Event::listen(RaidCompleted::class, [RecordActivityLog::class, 'handleRaidCompleted']);
        Event::listen(MdnEvent::class, [RecordActivityLog::class, 'handleMdnEvent']);
        Event::listen(TileCombatResolved::class, [RecordActivityLog::class, 'handleTileCombatResolved']);
    }
}
