<?php

use Illuminate\Support\Facades\Schedule;

// Production runs `php artisan schedule:run` every minute from cron (deploy/, M8).

Schedule::command('files:prune-abandoned')->hourly()->withoutOverlapping();
