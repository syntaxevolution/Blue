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
// whether the scheduler is firing at all.
//
// NOT using `runInBackground()`: it relies on `proc_open`/`exec` to
// fork a detached child, which DirectAdmin + some shared PHP-FPM
// configurations strip out via disable_functions. When that happens,
// the scheduler silently treats the command as "launched" but no
// child ever runs. Inline execution is safer here — the tick should
// finish in under a second even with dozens of bots, and the mutex
// prevents any overlap anyway.
Schedule::command('bots:tick')
    ->cron("*/{$interval} * * * *")
    ->withoutOverlapping(10)
    ->appendOutputTo(storage_path('logs/bots_tick.log'));
