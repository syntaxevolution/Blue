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

// `withoutOverlapping(10)` sets an explicit 10-minute expiration on
// the mutex lock so a crashed or SIGKILL'd tick (OOM, VPS reboot,
// PHP fatal) can't wedge the scheduler forever. Laravel's default
// expiry is 24 hours, which is way too long for a 5-minute job.
//
// `appendOutputTo` lets us tail bots_tick.log on the server to see
// whether the scheduler is firing at all; `emailOutputOnFailure`
// is intentionally NOT used because the app doesn't have a mailer
// configured for admin alerts yet.
Schedule::command('bots:tick')
    ->cron("*/{$interval} * * * *")
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/bots_tick.log'));
