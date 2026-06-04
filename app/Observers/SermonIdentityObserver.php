<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Sermon;
use App\Services\Processing\SermonIdentitySyncService;

class SermonIdentityObserver
{
    public function __construct(
        private readonly SermonIdentitySyncService $identitySyncService,
    ) {}

    public function saving(Sermon $sermon): void
    {
        $this->identitySyncService->syncForPersistence($sermon);
    }
}
