<?php

namespace App\Providers;

use App\Domain\Combat\AttackService;
use App\Domain\Combat\CombatFormula;
use App\Domain\Combat\SpyService;
use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Drilling\DrillService;
use App\Domain\Economy\ExtraMovesService;
use App\Domain\Economy\ShopService;
use App\Domain\Economy\TeleportService;
use App\Domain\Economy\TransportService;
use App\Domain\Items\ItemBreakService;
use App\Domain\Items\PassiveBonusService;
use App\Domain\Items\StatOverflowService;
use App\Domain\Notifications\ActivityLogService;
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
        $this->app->singleton(ShopService::class);
        $this->app->singleton(CombatFormula::class);
        $this->app->singleton(SpyService::class);
        $this->app->singleton(AttackService::class);

        // Batch 1 additions
        $this->app->singleton(StatOverflowService::class);
        $this->app->singleton(ItemBreakService::class);
        $this->app->singleton(PassiveBonusService::class);
        $this->app->singleton(ExtraMovesService::class);
        $this->app->singleton(TransportService::class);
        $this->app->singleton(TransportMovementService::class);
        $this->app->singleton(TeleportService::class);
        $this->app->singleton(ActivityLogService::class);
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
    }
}
