<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\SermonTitleProvenance;
use App\Enums\TitleGenerationStrategy;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Sermon\SermonCreationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Establish title provenance for historic sermons created before the
 * `sermons.title_provenance` column existed.
 *
 * Those rows are deliberately left null by the migration, and null routes to the
 * legacy `PlaceholderSermonTitle` recogniser. That is right for most of them, but
 * it strands the exact rows the provenance column was introduced for: a title the
 * pipeline generated from a filename that the recogniser cannot safely match
 * ("Sunday 23 January 2022 101", "Carols By Candlelight 19 December 2021"). Those
 * keep refusing good banked analysis forever.
 *
 * Provenance is *proved*, never assumed. The generated title is a pure function
 * of the run's original filename, service date and service slot, so this
 * recomputes it and writes `Generated` only where the recomputed value is exactly
 * the stored one. Anything else — an editor's title, an AI title already applied,
 * a filename that no longer reproduces — is refused and left null, because a
 * non-null title is not editorial authority merely because it exists, and a wrong
 * `Generated` here would license overwriting a curated title.
 *
 * Deletion trigger: Delete after IC8 closeout once the Phase 7 title repair and
 * its retained evidence are complete.
 */
final class HistoricVideoSermonTitleProvenanceRepair
{
    public function __construct(
        private readonly SermonCreationService $sermonCreationService,
    ) {}

    /**
     * @param  list<string>  $processingIds
     * @return list<array{
     *     processing_id: string,
     *     sermon: Sermon,
     *     current_title: string,
     *     generated_title: string,
     *     current_provenance: string,
     *     disposition: 'pending'|'already_recorded'|'refused'
     * }>
     */
    public function inspect(HistoricImportOperation $operation, array $processingIds): array
    {
        if ($processingIds === [] || count($processingIds) !== count(array_unique($processingIds))) {
            throw new RuntimeException('Name every target processing ID exactly once.');
        }

        $runs = MediaProcessingLog::query()
            ->whereIn('processing_id', $processingIds)
            ->get()
            ->keyBy('processing_id');
        $missing = array_values(array_diff($processingIds, $runs->keys()->all()));

        if ($missing !== []) {
            throw new RuntimeException('Selected processing runs do not exist: '.implode(', ', $missing).'.');
        }

        $entries = [];

        foreach ($processingIds as $processingId) {
            $run = $runs->get($processingId);

            if (! $run instanceof MediaProcessingLog) {
                throw new RuntimeException("Processing run {$processingId} could not be loaded.");
            }

            $this->assertRunOwnership($run, $operation);
            $sermon = $this->sermonForRun($run, $operation);
            $generatedTitle = $this->generatedTitleFor($run, $sermon);

            $entries[] = [
                'processing_id' => $processingId,
                'sermon' => $sermon,
                'current_title' => (string) $sermon->title,
                'generated_title' => $generatedTitle,
                'current_provenance' => $sermon->title_provenance->value ?? 'null',
                'disposition' => $this->disposition($sermon, $generatedTitle),
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{
     *     processing_id: string,
     *     sermon: Sermon,
     *     current_title: string,
     *     generated_title: string,
     *     current_provenance: string,
     *     disposition: 'pending'|'already_recorded'|'refused'
     * }>  $entries
     * @return array{recorded: int, already_recorded: int, refused: int}
     */
    public function apply(HistoricImportOperation $operation, array $entries): array
    {
        return DB::transaction(function () use ($operation, $entries): array {
            $recorded = 0;
            $alreadyRecorded = 0;
            $refused = 0;

            foreach ($entries as $entry) {
                $run = MediaProcessingLog::query()
                    ->where('processing_id', $entry['processing_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $run instanceof MediaProcessingLog) {
                    throw new RuntimeException("Processing run {$entry['processing_id']} disappeared before title provenance repair.");
                }

                $this->assertRunOwnership($run, $operation);

                $sermon = Sermon::query()->whereKey($entry['sermon']->id)->lockForUpdate()->first();

                if (! $sermon instanceof Sermon) {
                    throw new RuntimeException("Sermon {$entry['sermon']->id} disappeared before title provenance repair.");
                }

                if ($sermon->livestream_processing_id !== $run->processing_id) {
                    throw new RuntimeException("Sermon {$sermon->id} is not owned by processing run {$run->processing_id}.");
                }

                $this->assertSermonOwnership($sermon, $operation);

                $generatedTitle = $this->generatedTitleFor($run, $sermon);

                match ($this->disposition($sermon, $generatedTitle)) {
                    'already_recorded' => $alreadyRecorded++,
                    'refused' => $refused++,
                    'pending' => (function () use ($sermon, &$recorded): void {
                        $sermon->forceFill(['title_provenance' => SermonTitleProvenance::Generated])->save();
                        $recorded++;
                    })(),
                };
            }

            return ['recorded' => $recorded, 'already_recorded' => $alreadyRecorded, 'refused' => $refused];
        });
    }

    /**
     * @return 'pending'|'already_recorded'|'refused'
     */
    private function disposition(Sermon $sermon, string $generatedTitle): string
    {
        if ($sermon->title_provenance === SermonTitleProvenance::Generated) {
            return 'already_recorded';
        }

        if ($sermon->title_provenance !== null) {
            return 'refused';
        }

        return (string) $sermon->title === $generatedTitle ? 'pending' : 'refused';
    }

    /**
     * Recompute the title the pipeline's filename fallback would produce today.
     *
     * `FilenameOnly` is the same derivation `AiWithFallback` reaches once ID3 and
     * AI analysis are both absent, which is exactly the state these rows were
     * created in, so an exact match is proof rather than resemblance.
     */
    private function generatedTitleFor(MediaProcessingLog $run, Sermon $sermon): string
    {
        $context = [
            'filename' => (string) $run->original_filename,
            'processing_log' => $run,
            'date' => $sermon->date->toDateString(),
        ];

        // The slot only participates when the row has one; the generator derives
        // it from the run otherwise, exactly as it did at creation.
        if ($sermon->service instanceof SermonService) {
            $context['service'] = $sermon->service;
        }

        return $this->sermonCreationService->generateTitle(
            TitleGenerationStrategy::FilenameOnly,
            $context,
        );
    }

    private function assertRunOwnership(MediaProcessingLog $run, HistoricImportOperation $operation): void
    {
        if ($run->status !== ProcessingStatus::Completed || $run->processing_type !== MediaType::Livestream) {
            throw new RuntimeException("Processing run {$run->processing_id} must be a completed livestream run.");
        }

        if ($run->historic_import_operation_id !== $operation->id
            || data_get($run->processing_metadata?->toArray(), 'historic_import.operation_id') !== $operation->operation_id
            || $run->historicImportJobKey() === null) {
            throw new RuntimeException("Processing run {$run->processing_id} does not belong to the named historic operation.");
        }
    }

    private function sermonForRun(MediaProcessingLog $run, HistoricImportOperation $operation): Sermon
    {
        $sermons = Sermon::query()
            ->where('livestream_processing_id', $run->processing_id)
            ->orderBy('id')
            ->get();

        if ($sermons->count() !== 1 || ! $sermons->first() instanceof Sermon) {
            throw new RuntimeException("Processing run {$run->processing_id} must identify exactly one sermon.");
        }

        $sermon = $sermons->first();

        if ($run->sermon_id !== null && $run->sermon_id !== $sermon->id) {
            throw new RuntimeException("Processing run {$run->processing_id} has an inconsistent sermon link.");
        }

        $this->assertSermonOwnership($sermon, $operation);

        return $sermon;
    }

    private function assertSermonOwnership(Sermon $sermon, HistoricImportOperation $operation): void
    {
        $quarantineDisk = (string) config('media-processing.storage.historic_quarantine_disk');

        if ($sermon->source_type !== SermonSourceType::Livestream
            || $sermon->publication_state !== SermonPublicationState::Quarantined
            || $sermon->asset_disk !== $quarantineDisk
            || $sermon->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException("Sermon {$sermon->id} is not private media owned by the named historic operation.");
        }
    }
}
