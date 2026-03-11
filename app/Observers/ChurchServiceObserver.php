<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ChurchService;
use App\Services\ChurchServiceReconciliationDispatcher;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ChurchServiceObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ChurchServiceReconciliationDispatcher $reconciliationDispatcher,
    ) {}

    public function created(ChurchService $churchService): void
    {
        $this->dispatchMatchingReconciliations($churchService);
    }

    public function updated(ChurchService $churchService): void
    {
        if (! $churchService->wasChanged(['date', 'service'])) {
            return;
        }

        $this->dispatchMatchingReconciliations($churchService);
    }

    private function dispatchMatchingReconciliations(ChurchService $churchService): void
    {
        $this->reconciliationDispatcher->dispatchForChurchService($churchService);
    }
}
