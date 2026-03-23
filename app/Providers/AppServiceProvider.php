<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\ParallelTestingProcessLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            $_SERVER['argv'] = ParallelTestingProcessLimiter::apply($_SERVER['argv']);
            $_SERVER['argc'] = count($_SERVER['argv']);
        }

        Password::defaults(function () {
            return Password::min(12)
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised();
        });
    }
}
