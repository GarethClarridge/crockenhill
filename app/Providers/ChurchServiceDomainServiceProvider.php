<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ChurchServiceCanonicalListChanged;
use App\Listeners\DispatchChurchServiceReconciliation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ChurchServiceDomainServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::listen(
            ChurchServiceCanonicalListChanged::class,
            DispatchChurchServiceReconciliation::class,
        );
    }
}
