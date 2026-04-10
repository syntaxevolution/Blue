<?php

namespace App\Providers;

use App\Domain\Config\GameConfigResolver;
use App\Domain\Config\RngService;
use App\Domain\Player\MapStateBuilder;
use App\Domain\Player\MoveRegenService;
use App\Domain\Player\TravelService;
use App\Domain\World\FogOfWarService;
use App\Domain\World\WorldService;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
