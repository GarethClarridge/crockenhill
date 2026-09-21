<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\TranscriptCoverageGap;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\ServiceTranscriptCoverageScreen;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Support\CanonicalJson;
use App\Support\ServiceArtifactDisk;
use Illuminate\Console\Command;
use Closure;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Count the sustained sound that banked transcripts do not account for.
 *
 * §4.3a H10 needs the repetition screen's miss rate, and a detector cannot
 * measure its own misses: scoring it on the cases it was tuned to catch reports
 * what it was adjusted to report. This command supplies the independent view
 * without a second decode and without listening, by reading the RMS log — a
 * measurement no language model touched — and asking how much the transcript
 * has to say about the seconds where the recording had sound.
 *
 * **Read-only, permanently.** There is no `--apply`. The screen raises no hold
 * and writes nothing: a gap is a candidate for a human minute, not a verdict
 * that speech was lost, and the population it runs over is the one whose miss
 * rate is being measured. Writing holds from it would contaminate the very
 * measurement it exists to produce.
 *
 * `--detector-negative` scopes to the runs the repetition screen cleared, which
 * is H10's population: runs it flagged are not evidence about what it misses.
 */
class ScreenTranscriptCoverageCommand extends Command
{
    protected $signature = 'service:screen-transcript-coverage
        {--run=* : Processing run ids to screen}
        {--operation=* : Historic import operation ids to screen}
        {--detector-negative : Only runs the repetition screen screened and cleared (H10 population)}
        {--all : Every historic run holding a banked full-service transcript}
        {--details : List each run with its gaps}
        {--json : Emit the whole report as JSON}';

    protected $description = 'Measure sustained sound that banked transcripts do not account for (read-only)';

    public function __construct(private readonly HistoricStagingContextRegistry $stagingContexts)
    {
        parent::__construct();
    }

    public function handle(
        ServiceTranscriptCoverageScreen $screen,
        ServiceTranscriptReader $transcripts,
        RmsAnalysisService $rms,
    ): int {
        $runs = $this->runs();

        if ($runs === []) {
            $this->error('Nothing to screen: pass --run, --operation, --detector-negative or --all.');

            return self::FAILURE;
        }

        $results = [];
        $unassessable = [];

        foreach ($runs as $run) {
            $outcome = $this->withinStagingContext(
                $run,
                fn (): array => $this->screenRun($run, $screen, $transcripts, $rms),
            );

            if (isset($outcome['reason'])) {
                $unassessable[] = ['run' => (int) $run->id, 'reason' => $outcome['reason']];

                continue;
            }

            $results[] = $outcome;
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'runs_screened' => count($results),
            'runs_unassessable' => count($unassessable),
            'runs_with_gaps' => count(array_filter($results, static fn (array $r): bool => $r['gaps'] !== [])),
            'total_gaps' => array_sum(array_map(static fn (array $r): int => count($r['gaps']), $results)),
            'total_gap_minutes' => round(array_sum(array_column($results, 'gap_seconds')) / 60, 1),
            'unassessable' => $unassessable,
            'results' => $results,
        ];

        return $this->render($report);
    }

    /**
     * Read and screen one run's artifacts.
     *
     * @return array<string, mixed>
     */
    private function screenRun(
        MediaProcessingLog $run,
        ServiceTranscriptCoverageScreen $screen,
        ServiceTranscriptReader $transcripts,
        RmsAnalysisService $rms,
    ): array {
        $rmsData = $this->rmsData($run, $rms);
        $transcript = $transcripts->tryRead($run);

        if ($rmsData === [] || $transcript === null) {
            return ['reason' => $rmsData === [] ? 'rms_unavailable' : 'transcript_unavailable'];
        }

        $gaps = $screen->screen($rmsData, $transcript, $this->excludedSpans($run));

        return [
            'run' => (int) $run->id,
            'duration_seconds' => round($transcript->duration, 1),
            'gaps' => array_map(static fn (TranscriptCoverageGap $gap): array => $gap->toArray(), $gaps),
            'gap_seconds' => round(array_sum(array_map(
                static fn (TranscriptCoverageGap $gap): float => $gap->seconds(),
                $gaps,
            )), 1),
        ];
    }

    /**
     * Run a read inside the run's own recorded staging context.
     *
     * Historic artifacts live under a per-run staging root, so reading them
     * from the ambient disk reports every run unavailable — a false-negative
     * that would look exactly like a corpus with no coverage gaps. A malformed
     * recorded context is not a reason to read without one: that would produce
     * the same false reading, so it is reported as unassessable instead.
     *
     * @param  Closure(): array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function withinStagingContext(MediaProcessingLog $run, Closure $read): array
    {
        try {
            $context = $run->historicStagingContext();
        } catch (Throwable) {
            return ['reason' => 'staging_context_unreadable'];
        }

        if ($context === null) {
            return $read();
        }

        /** @var array<string, mixed> */
        return $this->stagingContexts->within($context, $read);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): int
    {
        if ($this->option('json')) {
            $this->line(CanonicalJson::encodeReadable($report));

            return self::SUCCESS;
        }

        $this->table(['Measure', 'Value'], [
            ['Runs screened', $report['runs_screened']],
            ['Runs unassessable', $report['runs_unassessable']],
            ['Runs with gaps', $report['runs_with_gaps']],
            ['Total gaps', $report['total_gaps']],
            ['Total gap minutes', $report['total_gap_minutes']],
        ]);

        if ($this->option('details')) {
            foreach ($report['results'] as $result) {
                if ($result['gaps'] === []) {
                    continue;
                }

                $this->line("Run {$result['run']} — {$result['gap_seconds']}s over ".count($result['gaps']).' gap(s)');

                foreach ($result['gaps'] as $gap) {
                    $this->line(sprintf(
                        '    %.0fs–%.0fs  sound %.0fs  %d words  %.1f wpm',
                        $gap['start'],
                        $gap['end'],
                        $gap['sounded_seconds'],
                        $gap['words'],
                        $gap['words_per_minute'],
                    ));
                }
            }
        }

        $this->newLine();
        $this->info('Read-only: no holds were raised and nothing was written.');

        return self::SUCCESS;
    }

    /**
     * @return list<MediaProcessingLog>
     */
    private function runs(): array
    {
        $runIds = array_map('intval', (array) $this->option('run'));
        $operationIds = array_map('intval', (array) $this->option('operation'));
        $all = (bool) $this->option('all');
        $detectorNegative = (bool) $this->option('detector-negative');

        if ($runIds === [] && $operationIds === [] && ! $all && ! $detectorNegative) {
            return [];
        }

        $query = MediaProcessingLog::query()->whereNull('superseded_at');

        if ($runIds !== []) {
            $query->whereIn('id', $runIds);
        }

        if ($operationIds !== []) {
            $query->whereIn('historic_import_operation_id', $operationIds);
        }

        if ($all || $detectorNegative) {
            $query->whereNotNull('historic_import_operation_id')->where('status', 'completed');
        }

        return $query->get()
            ->reject(static fn (MediaProcessingLog $run): bool => $run->isExcluded())
            ->when($detectorNegative, fn ($runs) => $runs->filter(
                // Screened and clear. A run the screen never looked at says
                // nothing about what the screen misses, and a run it flagged is
                // not evidence about its misses either.
                static fn (MediaProcessingLog $run): bool => $run->recordedTranscriptSuspectBlocks() === [],
            ))
            ->values()
            ->pipe(static fn ($runs): array => array_values($runs->all()));
    }

    /**
     * Song sections, whose silence in a transcript is expected rather than a
     * finding — singing is routinely invisible to the decoder, and including
     * them would bury every real gap.
     *
     * @return list<array{start: float, end: float}>
     */
    private function excludedSpans(MediaProcessingLog $run): array
    {
        return $run->serviceSections()
            ->where('section_type', ServiceSectionType::Song)
            ->get()
            ->map(static fn ($section): array => [
                'start' => (float) $section->start_time,
                'end' => (float) $section->end_time,
            ])
            ->pipe(static fn ($spans): array => array_values($spans->all()));
    }

    /**
     * @return list<array{time: float, rms: float}>
     */
    private function rmsData(MediaProcessingLog $run, RmsAnalysisService $rms): array
    {
        $path = is_string($run->rms_log_path) && $run->rms_log_path !== '' ? $run->rms_log_path : null;

        if ($path === null) {
            return [];
        }

        try {
            $disk = ServiceArtifactDisk::for($path);

            if (! Storage::disk($disk)->exists($path)) {
                return [];
            }

            return $rms->extractRmsData((string) Storage::disk($disk)->get($path));
        } catch (Throwable) {
            return [];
        }
    }
}
