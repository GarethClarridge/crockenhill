<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ServiceSection;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Support\SectionReviewFlagPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Backfills the `structure_benediction_suspect` flag onto existing short closing
 * bible-reading sections (a doxology/benediction read at the service end) so the
 * review queue stops asking to confirm them — the detector already tags new runs
 * at detection time; this reconciles services processed before that path or that
 * lost the flag during re-derivation.
 *
 * Uses the same geometry rule as ServiceStructureValidator (the shared
 * isBenedictionSuspect helper), reading each section's run duration as the
 * recording length. Idempotent: a section already carrying the flag is skipped.
 */
class FlagClosingBenedictionsCommand extends Command
{
    protected $signature = 'services:flag-closing-benedictions
        {--execute : Persist the reported changes; without this option the command is a dry run}
        {--chunk=200 : Number of sections to inspect per chunk}
        {--service=* : Restrict to these church service IDs}';

    protected $description = 'Flag short closing bible-reading benedictions so they stop requiring review';

    public function handle(ChurchServiceReviewSynchronizer $reviewSynchronizer): int
    {
        $execute = (bool) $this->option('execute');
        $chunkSize = (int) $this->option('chunk');

        if ($chunkSize < 1) {
            $this->error('The --chunk option must be a positive integer.');

            return self::FAILURE;
        }

        /** @var list<int> $serviceIds */
        $serviceIds = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) $this->option('service'),
        )));

        if (! $execute) {
            $this->warn('DRY RUN enabled by default. No rows will be updated; pass --execute to persist these changes.');
        }

        $flaggedSections = 0;

        /** @var array<int, true> $touchedServiceIds */
        $touchedServiceIds = [];

        $this->candidateSectionsQuery($serviceIds)
            ->with('processingLog:id,duration,church_service_id')
            ->orderBy('id')
            ->chunkById($chunkSize, function (EloquentCollection $sections) use (
                &$flaggedSections,
                &$touchedServiceIds,
                $execute,
            ): void {
                foreach ($sections as $section) {
                    if (! $this->shouldFlag($section)) {
                        continue;
                    }

                    $flaggedSections++;

                    foreach ($this->serviceIdsForSection($section) as $serviceId) {
                        $touchedServiceIds[$serviceId] = true;
                    }

                    if (! $execute) {
                        continue;
                    }

                    $this->applyBenedictionFlag($section);
                }
            });

        $affectedServiceIds = array_keys($touchedServiceIds);
        $syncedServices = 0;

        if ($execute && $affectedServiceIds !== []) {
            ChurchService::query()
                ->whereKey($affectedServiceIds)
                ->orderBy('id')
                ->chunkById($chunkSize, function (EloquentCollection $services) use ($reviewSynchronizer, &$syncedServices): void {
                    foreach ($services as $service) {
                        $sections = $this->sectionsForService($service);
                        $reviewSynchronizer->sync(
                            $service,
                            $sections,
                            $service->import_metadata->reviewTriggers ?? [],
                        );
                        $syncedServices++;
                    }
                });
        }

        $this->table(
            ['Metric', $execute ? 'Applied' : 'Would apply'],
            [
                ['Benedictions flagged', $flaggedSections],
                ['Services re-synced', $execute ? $syncedServices : count($affectedServiceIds)],
            ],
        );

        return self::SUCCESS;
    }

    private function shouldFlag(ServiceSection $section): bool
    {
        $recordingDuration = $section->processingLog->duration;
        if (! is_numeric($recordingDuration)) {
            return false;
        }

        if (in_array(
            ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT,
            $this->reviewFlags($section),
            true,
        )) {
            return false;
        }

        return ServiceStructureValidator::isBenedictionSuspect(
            $section->section_type,
            (float) $section->duration,
            (float) $section->end_time,
            (float) $recordingDuration,
        );
    }

    private function applyBenedictionFlag(ServiceSection $section): void
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $reviewFlags = $this->reviewFlags($section);
        $reviewFlags[] = ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT;
        $metadata['review_flags'] = array_values(array_unique($reviewFlags));

        $section->forceFill([
            'metadata' => $metadata,
            'needs_manual_review' => SectionReviewFlagPolicy::requiresManualReview(
                $section->section_type,
                $metadata['review_flags'],
            ),
        ])->saveQuietly();
    }

    /**
     * @return array<int, string>
     */
    private function reviewFlags(ServiceSection $section): array
    {
        $flags = $section->metadata?->toArray()['review_flags'] ?? [];

        if (! is_array($flags)) {
            return [];
        }

        return array_values(array_filter($flags, 'is_string'));
    }

    /**
     * Short bible_reading sections on a winning (non-superseded) run — the only
     * rows that can be a closing benediction.
     *
     * @param  list<int>  $serviceIds
     * @return Builder<ServiceSection>
     */
    private function candidateSectionsQuery(array $serviceIds): Builder
    {
        return ServiceSection::query()
            ->where('section_type', ServiceSectionType::BibleReading->value)
            ->whereDoesntHave('processingLog', function (Builder $query): void {
                $query->whereNotNull('superseded_at');
            })
            ->when($serviceIds !== [], function (Builder $query) use ($serviceIds): void {
                $query->where(function (Builder $query) use ($serviceIds): void {
                    $query->whereHas('processingLog', fn (Builder $log): Builder => $log->whereIn('church_service_id', $serviceIds))
                        ->orWhereHas('churchServiceItem', fn (Builder $item): Builder => $item->whereIn('church_service_id', $serviceIds));
                });
            });
    }

    /**
     * @return list<int>
     */
    private function serviceIdsForSection(ServiceSection $section): array
    {
        $ids = [];

        $logServiceId = $section->processingLog->church_service_id;
        if (is_int($logServiceId)) {
            $ids[] = $logServiceId;
        }

        if ($section->church_service_item_id !== null) {
            $itemServiceId = $section->churchServiceItem?->church_service_id;
            if (is_int($itemServiceId)) {
                $ids[] = $itemServiceId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return EloquentCollection<int, ServiceSection>
     */
    private function sectionsForService(ChurchService $service): EloquentCollection
    {
        return ServiceSection::query()
            ->where(function (Builder $query) use ($service): void {
                $query->whereHas('processingLog', fn (Builder $log): Builder => $log->where('church_service_id', $service->id))
                    ->orWhereHas('churchServiceItem', fn (Builder $item): Builder => $item->where('church_service_id', $service->id));
            })
            ->get();
    }
}
