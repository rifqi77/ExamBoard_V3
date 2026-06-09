<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety-net sweep for abandoned exam attempts. Catches the case where a
// student's time runs out and they never reopen the tab — without this,
// their draft answers stay unsubmitted forever. Idempotent.
//
// To actually fire in dev: run `php artisan schedule:work` in a separate
// terminal. In prod: add `* * * * * php artisan schedule:run` to cron.
Schedule::command('exams:finalize-expired')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
