<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ChurchServiceCanonicalListChanged;
use App\Models\ChurchService;
use App\Services\ChurchServiceReconciliationDispatcher;

class DispatchChurchServiceReconciliation
{
    public function __construct(
        private readonly ChurchServiceReconciliationDispatcher $reconciliationDispatcher,
    ) {}

    public function handle(ChurchServiceCanonicalListChanged $event): void
    {
        $churchService = ChurchService::query()->find($event->churchServiceId);

        if (! $churchService instanceof ChurchService) {
            return;
        }

        $this->reconciliationDispatcher->dispatchForChurchService($churchService, [
            'event' => 'church_service_canonical_list_changed',
            'source' => $event->source,
            'changes' => $event->changes,
        ]);
    }
}
