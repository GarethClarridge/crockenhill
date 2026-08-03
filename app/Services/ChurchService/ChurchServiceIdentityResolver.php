<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\SermonService;
use App\Models\ChurchService;
use RuntimeException;

/**
 * Evidence staging resolves the service by its natural identity — date plus
 * service — under a lock. Zero rows means the caller creates one; exactly one
 * row is reused; more than one is a hard data-integrity failure that must never
 * be papered over by taking the first.
 *
 * `church_services_date_service_unique` should make the third case impossible,
 * so reaching it means the constraint is missing or has been bypassed, and no
 * import may proceed until that is understood.
 */
class ChurchServiceIdentityResolver
{
    public function resolve(string $date, SermonService $service): ?ChurchService
    {
        $matches = ChurchService::query()
            ->where('date', $date)
            ->where('service', $service->value)
            ->lockForUpdate()
            ->get();

        if ($matches->count() > 1) {
            throw new RuntimeException(
                "Church service identity {$date} {$service->value} resolves to ".$matches->count()
                .' rows ('.implode(', ', $matches->modelKeys()).'). Repair the duplicates before importing evidence.',
            );
        }

        return $matches->first();
    }
}
