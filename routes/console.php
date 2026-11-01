<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deletes device events (and their snapshots) older than EVENTS_RETENTION_DAYS.
// Runs when the scheduler is active: `php artisan schedule:work` (dev) or a cron entry.
Schedule::command('model:prune', ['--model' => [App\Models\DeviceEvent::class]])->daily();
