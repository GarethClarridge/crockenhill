<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__, 3))
    ->withSchedule(function (Schedule $schedule): void {
        $timezone = config('app.timezone', 'UTC');

        $schedule->command('inspire')->timezone($timezone)->daily();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/login');
    })
    ->create();
