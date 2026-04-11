<?php

use App\Domain\Config\GameConfig;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bot tick scheduler. Cadence comes from config/game.php
// `bots.tick_interval_minutes` so it can be retuned without a deploy.
// Wrapped in try/catch so a stale/missing config cache at boot never
// crashes the scheduler (it just falls back to the default 5 minutes).
try {
    $interval = (int) (GameConfig::get('bots.tick_interval_minutes', 5) ?? 5);
} catch (\Throwable) {
    $interval = 5;
}
$interval = max(1, $interval);

Schedule::command('bots:tick')
    ->cron("*/{$interval} * * * *")
    ->withoutOverlapping()
    ->runInBackground();
