<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\FlagSuspectTranscriptRepetition;
use App\Data\ChurchServiceTranscript;
use App\Data\SuspectTranscriptBlock;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\HistoricMedia\HistoricTranscriptRecoveryReplay;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Screen banked full-service transcripts for looping text, and optionally hold
 * the sermons whose delivered span contains it (P8-Q14).
 *
 * The pipeline screens every transcript it writes, but the 2026-09-09
 * correctness review's 125 looping sermons were all decoded before the screen
 * existed and no completed run will pass through that code again. This is how
 * the same rule reaches them, and it is deliberately the *same* rule: a hold
 * raised here and a hold raised by the pipeline mean the same thing, so a
 * recovered sermon reprocessed later withdraws its own hold without help.
 *
 * Reports without writing unless `--apply` is passed. Selection is explicit for
 * the reason P8-Q3 records: a bulk pass over `--all` that was meant for one
 * operation is not recoverable by re-running it.
 */
class ScreenTranscriptRepetitionCommand extends Command
{
    protected $signature = 'service:screen-transcript-repetition
        {--operation=* : Historic import operation ids to screen}
        {--run=* : Processing run ids to screen}
        {--all : Screen every run holding a banked full-service transcript}
        {--apply : Record the screen on each run and raise or clear the sermon hold}
        {--details : List each affected run with its blocks}
        {--json : Emit the whole report as JSON}';

    protected $description = 'Screen banked full-service transcripts for repeated-phrase loops and impossible word rates';

    public function handle(
        ServiceTranscriptReader $transcripts,
        ServiceTranscriptRepetitionScreen $screen,
        FlagSuspectTranscriptRepetition $hold,
        HistoricStagingContextRegistry $stagingContexts,
    ): int {
        try {
            $runs = $this->selection();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($runs === null) {
            $this->error('Name what to screen: --run, --operation, or --all.');

            return self::FAILURE;
        }

        $report = [
            'applied' => (bool) $this->option('apply'),
            'screened' => 0,
            'unreadable' => 0,
            'with_blocks' => 0,
            'blocks' => 0,
            'seconds' => 0.0,
            'sermon_span_held' => 0,
            'holds_raised' => 0,
            'holds_withdrawn' => 0,
            'runs' => [],
        ];

        foreach ($runs->lazyById() as $run) {
            // A historic run's transcript key resolves only inside its staging
            // context: outside it the same relative path names a different root
            // and every read reports the evidence missing.
            $transcript = $this->withRunContext(
                $stagingContexts,
                $run,
                static fn (): ?ChurchServiceTranscript => $transcripts->tryRead($run),
            );

            if (! $transcript instanceof ChurchServiceTranscript) {
                $report['unreadable']++;

                continue;
            }

            $report['screened']++;
            $blocks = $screen->screen($transcript);
            $spans = $this->sermonSpans($run);
            $withinSermon = $spans === [] ? [] : $screen->within($blocks, $spans);

            if ($blocks !== []) {
                $report['with_blocks']++;
                $report['blocks'] += count($blocks);
                $report['seconds'] += SuspectTranscriptBlock::coveredSeconds($blocks);
            }

            if ($withinSermon !== []) {
                $report['sermon_span_held']++;
            }

            if ((bool) $this->option('apply')) {
                $this->withRunContext(
                    $stagingContexts,
                    $run,
                    function () use ($run, $transcript, $blocks, $withinSermon, $hold, &$report): void {
                        $this->apply($run, $transcript, $blocks, $withinSermon, $hold, $report);
                    },
                );
            }

            if ((bool) $this->option('details') && $blocks !== []) {
                $report['runs'][] = [
                    'run' => $run->id,
                    'sermon' => $run->sermon_id,
                    'blocks' => count($blocks),
                    'sermon_span_blocks' => count($withinSermon),
                    'seconds' => round(SuspectTranscriptBlock::coveredSeconds($blocks), 1),
                    'worst' => $this->worst($blocks),
                ];
            }
        }

        $report['seconds'] = round($report['seconds'], 1);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    /**
     * @param  list<SuspectTranscriptBlock>  $blocks
     * @param  list<SuspectTranscriptBlock>  $withinSermon
     * @param  array<string, mixed>  $report
     */
    private function apply(
        MediaProcessingLog $run,
        ChurchServiceTranscript $transcript,
        array $blocks,
        array $withinSermon,
        FlagSuspectTranscriptRepetition $hold,
        array &$report,
    ): void {
        // Written through the same call as the transcript key, so the recorded
        // screen can never describe a transcript the row no longer points at.
        $run->putServiceTranscriptPath(
            (string) $run->serviceTranscriptPath(),
            $transcript->unobservableWindows,
            array_map(static fn (SuspectTranscriptBlock $block): array => $block->toArray(), $blocks),
        );

        $outcome = $hold($run, $blocks);

        $report['holds_raised'] += $outcome['raised'];
        $report['holds_withdrawn'] += $outcome['withdrawn'];
    }

    /**
     * The sermon's delivered spans, or none when the run cut no sermon.
     *
     * The recorded extraction plan is preferred over the outer bounds for the
     * same reason the sermon-transcript job prefers it: a concatenated cut drops
     * the hymn between the reading and the sermon, and a loop inside that hymn
     * is not in the sermon a listener receives.
     *
     * @return list<array{start: float, end: float}>
     */
    private function sermonSpans(MediaProcessingLog $run): array
    {
        $spans = $run->recordedSermonExtractionSpans();

        if ($spans !== null) {
            return $spans;
        }

        if ($run->sermon_start_time === null || $run->sermon_end_time === null) {
            return [];
        }

        return [['start' => (float) $run->sermon_start_time, 'end' => (float) $run->sermon_end_time]];
    }

    /**
     * Run the callback inside the run's staging context when it has one.
     *
     * Mirrors {@see HistoricTranscriptRecoveryReplay}:
     * work run outside the context writes to the unscoped root instead — same
     * relative path, different root — which is how a pass can report both
     * "nothing found" and "nothing written" while both are true of the wrong
     * directory.
     *
     * @template TResult
     *
     * @param  \Closure(): TResult  $callback
     * @return TResult
     */
    private function withRunContext(HistoricStagingContextRegistry $stagingContexts, MediaProcessingLog $run, \Closure $callback): mixed
    {
        $context = $run->historicStagingContext();

        if ($context === null) {
            return $callback();
        }

        return $stagingContexts->within($context, $callback);
    }

    /** @return Builder<MediaProcessingLog>|null */
    private function selection(): ?Builder
    {
        /** @var list<string> $runIds */
        $runIds = (array) $this->option('run');
        /** @var list<string> $operationIds */
        $operationIds = (array) $this->option('operation');

        $query = MediaProcessingLog::query()->orderBy('id');

        if ($runIds !== []) {
            $wanted = array_map('intval', $runIds);
            $found = $query->clone()->whereIn('id', $wanted)->pluck('id')->all();
            $missing = array_values(array_diff($wanted, $found));

            if ($missing !== []) {
                // Named runs that cannot be found are an error, never an empty
                // pass. A run is invisible whenever the app is pointed at
                // another database — which is what `artisan dusk` does for the
                // length of its suite — and reporting that as "nothing to do"
                // silently skips real work while looking like success.
                throw new RuntimeException(
                    'These runs could not be found: '.implode(', ', $missing)
                    .'. Check nothing has repointed the database, such as a Dusk run in progress.'
                );
            }

            return $query->whereIn('id', $wanted);
        }

        if ($operationIds !== []) {
            return $query->whereIn('historic_import_operation_id', array_map('intval', $operationIds));
        }

        return (bool) $this->option('all') ? $query : null;
    }

    /**
     * @param  list<SuspectTranscriptBlock>  $blocks
     * @return array<string, mixed>
     */
    private function worst(array $blocks): array
    {
        $worst = $blocks[0];

        foreach ($blocks as $block) {
            if ($block->seconds() > $worst->seconds()) {
                $worst = $block;
            }
        }

        return $worst->toArray();
    }

    /** @param  array<string, mixed>  $report */
    private function render(array $report): void
    {
        $this->table(['Measure', 'Runs'], [
            ['Screened', $report['screened']],
            ['Transcript unreadable', $report['unreadable']],
            ['Holding suspect blocks', $report['with_blocks']],
            ['Suspect inside the sermon span', $report['sermon_span_held']],
        ]);

        $this->line(sprintf(
            '%d block(s) covering %.0f second(s).',
            $report['blocks'],
            $report['seconds'],
        ));

        if ((bool) $report['applied']) {
            $this->line(sprintf(
                'Holds raised: %d. Holds withdrawn: %d.',
                $report['holds_raised'],
                $report['holds_withdrawn'],
            ));
        } else {
            $this->comment('Nothing was written. Pass --apply to record the screen and hold the affected sermons.');
        }

        foreach ($report['runs'] as $row) {
            $this->line(sprintf(
                '  run %d / sermon %s: %d block(s), %d in the sermon span, %.0fs — "%s" ×%s',
                $row['run'],
                $row['sermon'] ?? '—',
                $row['blocks'],
                $row['sermon_span_blocks'],
                $row['seconds'],
                $row['worst']['phrase'] ?? $row['worst']['reason'],
                $row['worst']['repeats'] ?? '—',
            ));
        }
    }
}
