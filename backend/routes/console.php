<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('uptime:check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('billing:run')->dailyAt('08:00')->timezone('America/Toronto')->onOneServer();
