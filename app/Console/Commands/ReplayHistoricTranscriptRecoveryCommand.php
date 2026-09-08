<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricTranscriptRecoveryReplay;
use App\Services\HistoricMedia\TranscriptRecoveryReplayEntry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

/**
 * Re-applies transcript recovery to the runs that banked their retries before
 * the region-wise rule existed.
 *
 * These runs discarded a whole re-transcription because one region of it still
 * looped, banking the entire window as `retranscription_failed` and deleting
 * every recovered word with it. The retries themselves survive — they are
 * archived before the recovery decides what to do with them — so the correction
 * calls no provider, needs no ffmpeg, and does not depend on the source
 * recording still existing. {@see HistoricTranscriptRecoveryReplay}.
 *
 * Membership is re-derived on every run rather than read from a stored list: a
 * replayed run reports as "already replayed" afterwards, so an interrupted pass
 * resumes by simply running the command again.
 *
 * This command does not re-analyse anything. Recovered evidence can move a
 * sermon's whole basis — one sermon goes from 81 words of closing prayer to
 * 3,324 words opening mid-lectern — but re-placing a boundary and re-deriving a
 * title are separate steps with separate costs, taken deliberately once the
 * replay has landed and the change is visible.
 *
 * Deletion trigger: delete once every affected historic run is replayed and the
 * Phase 8 closeout retention window has expired.
 */
class ReplayHistoricTranscriptRecoveryCommand extends Command
{
    protected $signature = 'historic-import:replay-transcript-recovery
                            {--operation= : Restrict to this historic operation ID}
                            {--processing-id=* : Restrict to these exact processing IDs}
                            {--run=* : Restrict to these media processing log IDs}
                            {--limit= : Inspect at most this many runs, oldest first}
                            {--execute : Bank the replayed transcripts (default: dry run)}
                            {--show-unaffected : List runs needing no replay as well}
                            {--json= : Also write the full per-run census to this path}';

    protected $description = 'Re-apply transcript recovery to historic runs using the retries they already banked';

    public function handle(HistoricTranscriptRecoveryReplay $replay): int
    {
        try {
            $execute = (bool) $this->option('execute');
            $runs = $this->runsQuery()->get();

            if ($runs->isEmpty()) {
                $this->warn('No runs matched this selection.');

                return self::SUCCESS;
            }

            $this->line(sprintf('Inspecting %d run(s)…', $runs->count()));
            $entries = $replay->inspect($runs);

            $this->report($entries);
            $this->writeJson($entries);

            $replayable = array_values(array_filter(
                $entries,
                static fn (TranscriptRecoveryReplayEntry $entry): bool => $entry->isReplayable(),
            ));

            if (! $execute) {
                $this->warn('DRY RUN: nothing was written.');
                $this->warn(sprintf('%d transcript(s) would be replayed.', count($replayable)));
                $this->line('Re-run with --execute for this exact selection.');

                return self::SUCCESS;
            }

            $totals = $replay->apply($entries);
            $this->info("Banked {$totals['replayed']} replayed transcript(s).");

            foreach ($totals['failures'] as $failure) {
                $this->error($failure);
            }

            if ($totals['replayed'] > 0) {
                $this->warn('Structure and analysis banked for these runs still derive from the old transcript.');
            }

            return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  list<TranscriptRecoveryReplayEntry>  $entries
     */
    private function report(array $entries): void
    {
        $showUnaffected = (bool) $this->option('show-unaffected');
        $rows = [];
        $dispositions = [];
        $outcomes = [];
        $blindBefore = 0.0;
        $blindAfter = 0.0;
        $wordsBefore = 0;
        $wordsAfter = 0;
        $windowsBefore = 0;
        $windowsAfter = 0;

        foreach ($entries as $entry) {
            $dispositions[$entry->disposition] = ($dispositions[$entry->disposition] ?? 0) + 1;

            if (! $entry->isReplayable()) {
                if ($showUnaffected) {
                    $rows[] = [$entry->logId, $entry->processingId, '-', '-', '-', '-', '-', $entry->disposition.($entry->reason === null ? '' : ' ('.$entry->reason.')')];
                }

                continue;
            }

            $outcomes[$entry->outcome()] = ($outcomes[$entry->outcome()] ?? 0) + 1;
            $blindBefore += $entry->blindSecondsBefore;
            $blindAfter += $entry->blindSecondsAfter;
            $wordsBefore += $entry->wordsBefore;
            $wordsAfter += $entry->wordsAfter;
            $windowsBefore += $entry->windowCountBefore;
            $windowsAfter += $entry->windowCountAfter;

            $rows[] = [
                $entry->logId,
                $entry->processingId,
                sprintf('%d/%d', $entry->retriesFound, $entry->detectedWindows),
                sprintf('%d -> %d', $entry->windowCountBefore, $entry->windowCountAfter),
                sprintf('%.0f -> %.0f', $entry->blindSecondsBefore, $entry->blindSecondsAfter),
                sprintf('%+d', $entry->wordsRecovered()),
                sprintf('%+.0f', $entry->blindSecondsRecovered()),
                $entry->outcome(),
            ];
        }

        if ($rows !== []) {
            $this->table(
                ['Run', 'Processing ID', 'Retries', 'Windows', 'Blind s', 'Words', 'Recovered s', 'Outcome'],
                $rows,
            );
        }

        ksort($dispositions);

        foreach ($dispositions as $disposition => $count) {
            $this->line(sprintf('%-20s %d', $disposition, $count));
        }

        if ($outcomes === []) {
            return;
        }

        $this->newLine();
        ksort($outcomes);

        foreach ($outcomes as $outcome => $count) {
            $this->line(sprintf('%-20s %d run(s)', $outcome, $count));
        }

        $this->newLine();
        $this->line(sprintf('windows   %d -> %d', $windowsBefore, $windowsAfter));
        $this->line(sprintf(
            'blind     %.2f h -> %.2f h  (recovered %.2f h, %.1f%%)',
            $blindBefore / 3600,
            $blindAfter / 3600,
            ($blindBefore - $blindAfter) / 3600,
            $blindBefore > 0 ? 100 * ($blindBefore - $blindAfter) / $blindBefore : 0,
        ));
        $this->line(sprintf('words     %d -> %d  (%+d)', $wordsBefore, $wordsAfter, $wordsAfter - $wordsBefore));
    }

    /**
     * @param  list<TranscriptRecoveryReplayEntry>  $entries
     */
    private function writeJson(array $entries): void
    {
        $path = $this->option('json');

        if (! is_string($path) || trim($path) === '') {
            return;
        }

        $payload = array_map(static fn (TranscriptRecoveryReplayEntry $entry): array => [
            'log_id' => $entry->logId,
            'processing_id' => $entry->processingId,
            'disposition' => $entry->disposition,
            'reason' => $entry->reason,
            'outcome' => $entry->outcome(),
            'detected_windows' => $entry->detectedWindows,
            'retries_found' => $entry->retriesFound,
            'window_count_before' => $entry->windowCountBefore,
            'window_count_after' => $entry->windowCountAfter,
            'blind_seconds_before' => $entry->blindSecondsBefore,
            'blind_seconds_after' => $entry->blindSecondsAfter,
            'words_before' => $entry->wordsBefore,
            'words_after' => $entry->wordsAfter,
            'windows_after' => $entry->windowsAfter,
        ], $entries);

        file_put_contents(trim($path), json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        $this->line('Census written to '.trim($path));
    }

    /**
     * Runs carrying at least one unobservable window.
     *
     * Selection is on the banked window rather than on a date or a batch: the
     * defect is visible in the run's own metadata, and a run it never touched has
     * nothing to replay.
     *
     * @return Builder<MediaProcessingLog>
     */
    private function runsQuery(): Builder
    {
        $query = MediaProcessingLog::query()
            ->whereRaw('JSON_LENGTH(JSON_EXTRACT(processing_metadata, "$.service_transcript_unobservable_windows")) > 0')
            ->orderBy('id');

        $operationId = $this->option('operation');

        if (is_string($operationId) && trim($operationId) !== '') {
            $operation = HistoricImportOperation::query()->where('operation_id', trim($operationId))->first();

            if (! $operation instanceof HistoricImportOperation) {
                throw new RuntimeException('The named historic operation does not exist.');
            }

            $query->where('historic_import_operation_id', $operation->id);
        }

        $processingIds = $this->stringList('processing-id');

        if ($processingIds !== []) {
            $query->whereIn('processing_id', $processingIds);
        }

        $runIds = $this->stringList('run');

        if ($runIds !== []) {
            $query->whereIn('id', array_map(intval(...), $runIds));
        }

        $limit = $this->option('limit');

        if (is_string($limit) && trim($limit) !== '') {
            if (! ctype_digit(trim($limit)) || (int) $limit < 1) {
                throw new RuntimeException('--limit must be a positive integer.');
            }

            $query->limit((int) $limit);
        }

        return $query;
    }

    /** @return list<string> */
    private function stringList(string $option): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', (array) $this->option($option)),
        ));
    }
}
