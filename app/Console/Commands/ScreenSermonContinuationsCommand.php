<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\FlagSermonPartsNotExtracted;
use App\Data\ServiceSectionMetadata;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\SermonContinuationScreen;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

/**
 * Record the sections that carry part of a sermon the detector split (P8-Q15).
 *
 * The detector named these parts in its own notes and the pipeline discarded the
 * sentence. No completed run will pass through that code again, so this is how
 * the reading reaches the 442 banked historic sermons.
 *
 * Reports without writing unless `--apply`, and refuses to run without an
 * explicit selection — the reason P8-Q3 records: a bulk pass over `--all` that
 * was meant for one operation is not recoverable by re-running it.
 *
 * **Applying marks; it does not repair.** A marked run's stored media and sermon
 * text still cover the parts they were cut from, so
 * {@see FlagSermonPartsNotExtracted} holds each affected sermon until a
 * re-extraction makes the plan and the media agree. The hold is derived from
 * that comparison, so it withdraws itself rather than needing a second pass.
 */
class ScreenSermonContinuationsCommand extends Command
{
    protected $signature = 'service:screen-sermon-continuations
        {--operation=* : Historic import operation ids to screen}
        {--run=* : Processing run ids to screen}
        {--all : Screen every run}
        {--apply : Record the continuation marker and hold the sermons it makes incomplete}
        {--json : Emit the whole report as JSON}';

    protected $description = 'Find sections the detector named as further parts of a sermon, and hold the sermons missing them';

    public function handle(SermonContinuationScreen $screen, FlagSermonPartsNotExtracted $hold): int
    {
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
            'runs_with_parts' => 0,
            'parts' => 0,
            'parts_missing_from_media' => 0,
            'part_seconds' => 0.0,
            'missing_seconds' => 0.0,
            'already_recorded' => 0,
            'markers_written' => 0,
            'holds_raised' => 0,
            'holds_withdrawn' => 0,
            'failed' => 0,
            'candidates' => [],
        ];

        foreach ($runs->lazyById() as $run) {
            $report['screened']++;

            $sections = $run->serviceSections()->orderBy('start_time')->get();
            $candidates = $screen->screen($sections);

            if ($candidates === []) {
                continue;
            }

            $report['runs_with_parts']++;
            $recordedSpans = $run->recordedSermonExtractionSpans() ?? [];

            foreach ($candidates as $candidate) {
                /** @var ServiceSection $section */
                $section = $candidate['section'];
                $recorded = $screen->isRecorded($section, $candidate['sermon_section_id']);

                // Whether this part is already inside the media the run published.
                // The sermon-end rule runs the span forward over trailing `other`
                // material, so a part directly after the sermon is often already
                // there — recording it changes the plan's shape but omits nothing.
                $missingSeconds = $recordedSpans === []
                    ? 0.0
                    : $hold->uncoveredSeconds((float) $section->start_time, (float) $section->end_time, $recordedSpans);

                $report['parts']++;
                $report['part_seconds'] += (float) $section->end_time - (float) $section->start_time;
                $report['missing_seconds'] += $missingSeconds;

                if ($missingSeconds > 1.0) {
                    $report['parts_missing_from_media']++;
                }

                if ($recorded) {
                    $report['already_recorded']++;
                }

                $report['candidates'][] = [
                    'run' => $run->id,
                    'sermon' => $run->sermon_id,
                    'section' => $section->id,
                    'type' => $section->section_type->value,
                    'start' => round((float) $section->start_time, 1),
                    'end' => round((float) $section->end_time, 1),
                    'minutes' => round(((float) $section->end_time - (float) $section->start_time) / 60, 1),
                    'recorded' => $recorded,
                    'missing_minutes' => round($missingSeconds / 60, 1),
                    'evidence' => $candidate['evidence'],
                ];

                if ((bool) $this->option('apply') && ! $recorded) {
                    $this->record($section, $screen, $candidate['sermon_section_id'], $candidate['evidence']);
                    $report['markers_written']++;
                }
            }

            if ((bool) $this->option('apply')) {
                try {
                    $outcome = $hold($run->fresh() ?? $run);
                    $report['holds_raised'] += $outcome['raised'];
                    $report['holds_withdrawn'] += $outcome['withdrawn'];
                } catch (Throwable $exception) {
                    // A run whose plan cannot be resolved is reported, never counted
                    // as clean: an unassessable sermon is exactly the one a silent
                    // pass would publish short.
                    $report['failed']++;
                    $this->error(sprintf('Run %d: could not assess the sermon span — %s', $run->id, $exception->getMessage()));
                }
            }
        }

        $report['part_seconds'] = round($report['part_seconds'], 1);
        $report['missing_seconds'] = round($report['missing_seconds'], 1);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->render($report);

        return $report['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function record(ServiceSection $section, SermonContinuationScreen $screen, int $sermonSectionId, string $evidence): void
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $metadata['sermon_continuation'] = $screen->markerFor($sermonSectionId, $evidence)->toArray();

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        $this->info(sprintf(
            '%s %d runs: %d sermon parts across %d runs (%.1f minutes), %d already recorded.',
            $report['applied'] ? 'Applied to' : 'Screened',
            $report['screened'],
            $report['parts'],
            $report['runs_with_parts'],
            $report['part_seconds'] / 60,
            $report['already_recorded'],
        ));
        $this->info(sprintf(
            '%d of those parts are missing from the published media (%.1f minutes); the rest are already inside the span.',
            $report['parts_missing_from_media'],
            $report['missing_seconds'] / 60,
        ));

        if ($report['candidates'] !== []) {
            $this->table(
                ['Run', 'Sermon', 'Section', 'Type', 'Start', 'Minutes', 'Missing', 'Recorded', 'Evidence'],
                array_map(static fn (array $row): array => [
                    $row['run'],
                    $row['sermon'] ?? '—',
                    $row['section'],
                    $row['type'],
                    $row['start'],
                    $row['minutes'],
                    $row['missing_minutes'],
                    $row['recorded'] ? 'yes' : 'no',
                    mb_strimwidth($row['evidence'], 0, 70, '…'),
                ], $report['candidates']),
            );
        }

        if ($report['applied']) {
            $this->info(sprintf(
                '%d markers written; %d sermons held as missing parts, %d holds withdrawn, %d runs could not be assessed.',
                $report['markers_written'],
                $report['holds_raised'],
                $report['holds_withdrawn'],
                $report['failed'],
            ));
            $this->warn('A marker is not a repair: each held run needs re-extraction before its media and text contain the part.');

            return;
        }

        $this->warn('Nothing was written. Pass --apply to record these markers and hold the sermons missing them.');
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
                // Named runs that cannot be found are an error, never an empty pass:
                // a run is invisible whenever the app is pointed at another database,
                // which is what `artisan dusk` does for the length of its suite.
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
}
