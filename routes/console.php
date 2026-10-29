<?php

use Illuminate\Support\Facades\Schedule;

// Prepared, but disabled in every example/local environment until explicitly authorized.
if (config('scraping.scheduler_enabled')) {
    Schedule::command('documents:sync lex --mode=daily --limit=30')
        ->dailyAt(config('scraping.daily_time'))
        ->timezone(config('scraping.timezone'))
        ->withoutOverlapping()
        ->onOneServer();
}
