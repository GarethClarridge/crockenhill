<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\SectionSourceBoundsEntry;
use App\Services\HistoricMedia\SectionSourceBoundsScreen;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

/**
 * Screen every section against the length of the media it is timed on, and with
 * `--execute` hold its bounds to what that media contains.
 *
 * P8-Q17. Membership is re-derived every run rather than read from a list: a
 * clamped section reports its overrun from the detector's preserved claim, so a
 * second pass is a no-op that still shows its working, and an interrupted pass
 * resumes by running the command again.
 *
 * Nothing here calls a provider or re-cuts media. The clamp narrows a stored
 * bound to a measured one; the hold it raises is what says the content inside
 * that bound may be incomplete.
 *
 * Deletion trigger: delete once no historic run reports an unmeasurable or
 * past-source-end section and the Phase 8 release census has run, alongside
 * {@see SectionSourceBoundsScreen}. The `fromCues()` fix this screen exists to
 * clean up after keeps new runs out of its way, so it retires with the corpus
 * rather than becoming standing surface.
 */
class ScreenSectionSourceBoundsCommand extends Command
{
    protected $signature = 'historic-import:screen-section-bounds
                            {--operation= : Restrict to this historic operation ID}
                            {--processing-id=* : Restrict to these exact processing IDs}
                            {--execute : Write the resolved bounds (default: dry run)}
                            {--show-within : List sections needing no change as well}';

    protected $description = 'Check service section bounds against measured source duration and clamp impossible ends';

    public function handle(SectionSourceBoundsScreen $screen): int
    {
        try {
            $runs = $this->runsQuery()->get();

            if ($runs->isEmpty()) {
                $this->warn('No runs matched this selection.');

                return self::SUCCESS;
            }

            $entries = $screen->inspect($runs);
            $this->report($entries);

            $unmeasurable = $this->runsWithoutDuration($entries);

            if ($unmeasurable > 0) {
                $this->warn(sprintf(
                    '%d run(s) record no source duration, so their sections were neither passed nor failed. '
                    .'Resolve them with historic-import:backfill-source-durations first.',
                    $unmeasurable,
                ));
            }

            /*
             * Louder than a table row, because this is the one disposition the
             * command cannot act on at all: the section lies wholly outside its
             * media, so there is no bound to narrow to and an operator has to
             * decide what the section was ever describing. None exist today,
             * and a silent exit code would be the wrong way to learn one does.
             */
            $beyondStart = count(array_filter(
                $entries,
                static fn (SectionSourceBoundsEntry $entry): bool => $entry->disposition === SectionSourceBoundsScreen::DispositionBeyondSourceStart,
            ));

            if ($beyondStart > 0) {
                $this->warn(sprintf(
                    '%d section(s) start past the end of their own media. No clamp can express that, so they are left '
                    .'exactly as they are and need an operator decision.',
                    $beyondStart,
                ));
            }

            $repairable = array_values(array_filter(
                $entries,
                static fn (SectionSourceBoundsEntry $entry): bool => $entry->isRepairable(),
            ));

            if (! (bool) $this->option('execute')) {
                $this->warn('DRY RUN: nothing was written.');
                $this->warn(sprintf('%d section bound(s) would be resolved.', count($repairable)));
                $this->line('Re-run with --execute for this exact selection.');

                return self::SUCCESS;
            }

            $totals = $screen->apply($entries);

            $this->info(sprintf(
                'Clamped %d, restored %d, held %d, released %d, unchanged %d.',
                $totals['clamped'],
                $totals['restored'],
                $totals['held'],
                $totals['released'],
                $totals['unchanged'],
            ));

            foreach ($totals['failures'] as $failure) {
                $this->error($failure);
            }

            return $totals['failures'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  list<SectionSourceBoundsEntry>  $entries
     */
    private function report(array $entries): void
    {
        $showWithin = (bool) $this->option('show-within');
        $rows = [];
        $counts = [];

        foreach ($entries as $entry) {
            $counts[$entry->disposition] = ($counts[$entry->disposition] ?? 0) + 1;

            if (! $showWithin && $entry->disposition === SectionSourceBoundsScreen::DispositionWithin) {
                continue;
            }

            $rows[] = [
                (string) $entry->sectionId,
                (string) $entry->logId,
                $entry->sectionType->value,
                $entry->publicationStatus,
                sprintf('%.2f', $entry->startTime),
                sprintf('%.2f', $entry->recordedEnd),
                $entry->measuredDuration === null ? '-' : sprintf('%.2f', $entry->measuredDuration),
                $entry->overrunSeconds() > 0.0 ? sprintf('%.2f', $entry->overrunSeconds()) : '-',
                $entry->cuesPastSourceEnd === null ? '?' : (string) $entry->cuesPastSourceEnd,
                $entry->disposition.($entry->reason === null ? '' : ' ('.$entry->reason.')'),
            ];
        }

        if ($rows !== []) {
            $this->table(
                ['Section', 'Run', 'Type', 'Publication', 'Start', 'Claimed end', 'Media', 'Overrun', 'Cues past', 'Disposition'],
                $rows,
            );
        }

        ksort($counts);

        foreach ($counts as $disposition => $count) {
            $this->line(sprintf('%-22s %d', $disposition, $count));
        }
    }

    /**
     * @param  list<SectionSourceBoundsEntry>  $entries
     */
    private function runsWithoutDuration(array $entries): int
    {
        $logIds = [];

        foreach ($entries as $entry) {
            if ($entry->disposition === SectionSourceBoundsScreen::DispositionUnmeasurable) {
                $logIds[$entry->logId] = true;
            }
        }

        return count($logIds);
    }

    /**
     * @return Builder<MediaProcessingLog>
     */
    private function runsQuery(): Builder
    {
        $query = MediaProcessingLog::query()
            ->whereHas('serviceSections')
            ->orderBy('id');

        $operationId = $this->option('operation');

        if (is_string($operationId) && trim($operationId) !== '') {
            $operation = HistoricImportOperation::query()->where('operation_id', trim($operationId))->first();

            if (! $operation instanceof HistoricImportOperation) {
                throw new RuntimeException('The named historic operation does not exist.');
            }

            $query->where('historic_import_operation_id', $operation->id);
        }

        $processingIds = array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $this->option('processing-id')),
        ));

        if ($processingIds !== []) {
            $query->whereIn('processing_id', $processingIds);
        }

        return $query;
    }
}
