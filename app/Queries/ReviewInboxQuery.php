<?php

declare(strict_types=1);

namespace App\Queries;

use App\Actions\InboundEmail\InboundEmailPreviewFactory;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MediaProcessingIdentityResolver;
use Illuminate\Support\Carbon;

/**
 * One chronological review queue for everything that needs a human:
 * inbound emails, flagged sections, paused sermon-segment runs, pending
 * structure merges, and service-level review flags — grouped by service.
 *
 * Sections reuse ServiceReviewDashboardQuery::reviewGroups() (the
 * seven-reason predicate, contract C1); segment rows inherit the
 * blob-column-safe select (contract C3); each source is capped so the
 * unbounded dashboard grouping cannot balloon the page.
 *
 * @phpstan-type InboxGroup array{
 *     key: string,
 *     date: string|null,
 *     service_value: string|null,
 *     date_label: string,
 *     service_label: string,
 *     service: ChurchService|null,
 *     sort_date: string,
 *     service_sort: int,
 *     items: list<array<string, mixed>>
 * }
 */
class ReviewInboxQuery
{
    public const SOURCE_CAP = 50;

    private const UNATTRIBUTED_KEY = 'unattributed';

    /** @var array<string, ChurchService|null> */
    private array $serviceLookupCache = [];

    public function __construct(
        private readonly ServiceReviewDashboardQuery $dashboardQuery,
        private readonly InboundEmailPreviewFactory $previewFactory,
        private readonly MediaProcessingIdentityResolver $identityResolver,
    ) {}

    /**
     * @return array{
     *     groups: list<InboxGroup>,
     *     counts: array{all: int, emails: int, sections: int, segments: int, services: int}
     * }
     */
    public function build(): array
    {
        $groups = [];

        $counts = [
            'emails' => $this->collectEmails($groups),
            'sections' => $this->collectSections($groups),
            'segments' => $this->collectSegments($groups),
            'services' => $this->collectServiceItems($groups),
        ];
        $counts['all'] = array_sum($counts);

        $groups = array_values($groups);

        usort($groups, function (array $left, array $right): int {
            $unattributed = ($right['key'] === self::UNATTRIBUTED_KEY) <=> ($left['key'] === self::UNATTRIBUTED_KEY);
            if ($unattributed !== 0) {
                return $unattributed;
            }

            $dateCompare = strcmp($right['sort_date'], $left['sort_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return $left['service_sort'] <=> $right['service_sort'];
        });

        return ['groups' => $groups, 'counts' => $counts];
    }

    /**
     * @param  array<string, InboxGroup>  $groups
     */
    private function collectEmails(array &$groups): int
    {
        $emails = InboundEmail::query()
            ->whereIn('status', [
                InboundEmailStatus::Pending->value,
                InboundEmailStatus::Failed->value,
            ])
            ->orderByDesc('received_at')
            ->limit(self::SOURCE_CAP)
            ->get();

        foreach ($emails as $email) {
            $preview = $this->previewFactory->build($email);

            $date = $this->identityResolver->parseDate($preview['resolved_date']);
            $service = $this->identityResolver->parseService($preview['resolved_service']);

            $this->push($groups, $date, $service, [
                'kind' => 'email',
                'email' => $email,
                'preview' => $preview,
            ]);
        }

        return $emails->count();
    }

    /**
     * @param  array<string, InboxGroup>  $groups
     */
    private function collectSections(array &$groups): int
    {
        if (! (bool) config('media-processing.section_publishing.enabled', true)) {
            return 0;
        }

        $count = 0;

        foreach ($this->dashboardQuery->reviewGroups(self::SOURCE_CAP) as $reviewGroup) {
            foreach ($reviewGroup['sections'] as $entry) {
                if ($count >= self::SOURCE_CAP) {
                    return $count;
                }

                $this->push(
                    $groups,
                    $reviewGroup['date'],
                    $reviewGroup['service_enum'],
                    [
                        'kind' => 'section',
                        'section' => $entry['section'],
                        'reasons' => $entry['reasons'],
                        'review_reason' => $entry['review_reason'],
                        'audio_url' => $entry['audio_url'],
                        'video_url' => $entry['video_url'],
                    ],
                    $reviewGroup['service'],
                );

                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, InboxGroup>  $groups
     */
    private function collectSegments(array &$groups): int
    {
        // Explicit safe column list (TD-004B / contract C3): never hydrate the
        // oversized JSON blob columns for queue rendering.
        $runs = MediaProcessingLog::query()
            ->select([
                'id',
                'processing_id',
                'processing_type',
                'status',
                'current_step',
                'error_message',
                'original_filename',
                'extracted_date',
                'extracted_service',
                'processing_metadata',
                'updated_at',
            ])
            ->awaitingManualSermonReview()
            ->orderByDesc('updated_at')
            ->limit(self::SOURCE_CAP)
            ->get();

        foreach ($runs as $run) {
            $identity = $this->identityResolver->resolve($run);

            $this->push(
                $groups,
                $identity['date'] ?? null,
                $identity['service'] ?? null,
                [
                    'kind' => 'segment',
                    'run' => $run,
                ],
            );
        }

        return $runs->count();
    }

    /**
     * Pending structure merges and service-level review flags. Each source is
     * capped independently so a backlog of review flags can never push a
     * pending merge out of the queue (the hub's merge chip counts them all);
     * a service with both produces two entries in the same group.
     *
     * @param  array<string, InboxGroup>  $groups
     */
    private function collectServiceItems(array &$groups): int
    {
        /**
         * Performance Optimization: Limits retrieved columns for services to required
         * fields for group resolution and kind identification to reduce memory usage
         * and DB I/O in the capped inbox view.
         */
        $merges = ChurchService::query()
            ->select(['id', 'date', 'service', 'pending_structure_merge_source', 'needs_review'])
            ->whereNotNull('pending_structure_merge_source')
            ->orderByDesc('date')
            ->limit(self::SOURCE_CAP)
            ->get();

        $flagged = ChurchService::query()
            ->select(['id', 'date', 'service', 'pending_structure_merge_source', 'needs_review'])
            ->where('needs_review', true)
            ->orderByDesc('date')
            ->limit(self::SOURCE_CAP)
            ->get();

        foreach ($merges as $service) {
            $this->push($groups, $service->date->toDateString(), $service->service, [
                'kind' => 'merge',
                'service' => $service,
            ], $service);
        }

        foreach ($flagged as $service) {
            $this->push($groups, $service->date->toDateString(), $service->service, [
                'kind' => 'service_flag',
                'service' => $service,
            ], $service);
        }

        return $merges->count() + $flagged->count();
    }

    /**
     * @param  array<string, InboxGroup>  $groups
     * @param  Carbon|string|null  $date
     * @param  array<string, mixed>  $item
     */
    private function push(array &$groups, mixed $date, ?SermonService $service, array $item, ?ChurchService $serviceModel = null): void
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : (is_string($date) ? $date : null);

        $key = ($dateString !== null && $service instanceof SermonService)
            ? "{$dateString}|{$service->value}"
            : self::UNATTRIBUTED_KEY;

        if (! array_key_exists($key, $groups)) {
            $groups[$key] = [
                'key' => $key,
                'date' => $dateString,
                'service_value' => $service?->value,
                'date_label' => $dateString !== null ? Carbon::parse($dateString)->format('j M Y') : 'Unattributed',
                'service_label' => $service?->label() ?? '',
                'service' => null,
                'sort_date' => $dateString ?? '',
                'service_sort' => match ($service) {
                    SermonService::Morning => 1,
                    SermonService::Evening => 2,
                    SermonService::Other => 3,
                    default => 9,
                },
                'items' => [],
            ];
        }

        if ($serviceModel instanceof ChurchService && ! $groups[$key]['service'] instanceof ChurchService) {
            $groups[$key]['service'] = $serviceModel;
        }

        if (! $groups[$key]['service'] instanceof ChurchService && $dateString !== null && $service instanceof SermonService) {
            $groups[$key]['service'] = $this->lookupService($dateString, $service);
        }

        $groups[$key]['items'][] = $item;
    }

    private function lookupService(string $date, SermonService $service): ?ChurchService
    {
        $key = "{$date}|{$service->value}";

        if (! array_key_exists($key, $this->serviceLookupCache)) {
            $this->serviceLookupCache[$key] = ChurchService::query()
                ->whereDate('date', $date)
                ->where('service', $service->value)
                ->first();
        }

        return $this->serviceLookupCache[$key];
    }
}
