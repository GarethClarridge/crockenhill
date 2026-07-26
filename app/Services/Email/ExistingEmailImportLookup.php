<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\SermonService;
use App\Models\ChurchService;

/**
 * Answers whether a date already carries an order of service imported from a different email.
 *
 * A second email for a Sunday that already has one is the shape a correction, a forward, or a
 * duplicate send takes — all cases where importing without a second look can overwrite a record
 * someone has already reviewed. The provenance checked is `import_metadata.source_message_id`,
 * which only email-derived services carry, so services created by OpenLP or the livestream
 * pipeline never trigger it, and re-parsing the same email never flags its own import.
 */
class ExistingEmailImportLookup
{
    /**
     * Service values on the given date that came from an email other than the one supplied.
     *
     * @return list<string>
     */
    public function servicesImportedFromOtherEmails(
        string $date,
        ?string $sourceMessageId,
        ?SermonService $service = null,
    ): array {
        $serviceValue = $service?->value;

        /** @var list<string> $services */
        $services = ChurchService::query()
            ->whereDate('date', $date)
            ->when($serviceValue !== null, fn ($query) => $query->where('service', $serviceValue))
            ->get(['id', 'date', 'service', 'import_metadata'])
            ->filter(static function (ChurchService $churchService) use ($sourceMessageId): bool {
                $messageId = $churchService->import_metadata?->offsetGet('source_message_id');

                return is_string($messageId)
                    && trim($messageId) !== ''
                    && $messageId !== $sourceMessageId;
            })
            ->map(static fn (ChurchService $churchService): string => $churchService->service->value)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $services;
    }
}
