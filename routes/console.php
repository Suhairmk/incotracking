<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tracking:purge --photos')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('tracking:detect-interruptions')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
