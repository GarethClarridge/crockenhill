<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ChurchServiceCanonicalListChanged;
use App\Listeners\DispatchChurchServiceReconciliation;
use App\Services\ChurchService\HistoricConvergenceDispatchGuard;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ChurchServiceDomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * One guard per request/command so it registers its listeners once,
         * however many services a batch applies.
         */
        $this->app->singleton(HistoricConvergenceDispatchGuard::class);
    }

    public function boot(): void
    {
        Event::listen(
            ChurchServiceCanonicalListChanged::class,
            DispatchChurchServiceReconciliation::class,
        );
    }
}
