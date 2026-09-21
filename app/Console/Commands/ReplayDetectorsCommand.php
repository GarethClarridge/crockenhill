<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\DetectorEvaluation\DetectorReplay;
use App\Support\CanonicalJson;
use App\Support\DetectorCatalogue;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Re-read every promoted detector's recorded output and reconcile it against
 * the holds it raised.
 *
 * §4.3a H7's `detectors:replay`, and the command that discharges the checkbox
 * asking for every promoted detector to be re-run over the current eligible
 * historic membership and reconciled with existing holds.
 *
 * **Read-only, permanently.** There is no `--apply`. The whole value of this
 * report is that it describes what production actually decided; a command that
 * corrected what it measured would leave nothing to measure. Acting on a
 * discrepancy is an operator decision through the pipeline.
 *
 * **Historic and weekly are never merged.** They are different recording regimes
 * processed by different code paths, and one average over both would describe
 * neither. `--weekly` reports the weekly population on its own.
 *
 * The eligible denominator is computed here rather than quoted from the plan,
 * which warns that its own counts are dated baselines: 442 at one review, 437 at
 * another. Every rate in this report is against the number it prints.
 */
class ReplayDetectorsCommand extends Command
{
    protected $signature = 'detectors:replay
        {--run=* : Processing run ids to replay}
        {--weekly : Replay the weekly population instead of the historic one}
        {--all : Every eligible completed historic run (the default population)}
        {--details : List each detector with its held/unheld split}
        {--json : Emit the whole report as JSON}';

    protected $description = 'Reconcile every promoted detector\'s recorded output against its holds (read-only)';

    public function handle(DetectorReplay $replay): int
    {
        $runs = $this->population();

        if ($runs === []) {
            $this->components->error('No runs matched. Pass --all, --weekly or --run=.');

            return self::FAILURE;
        }

        $report = [
            'catalogue_version' => DetectorCatalogue::Version,
            'population' => $this->option('weekly') ? 'weekly' : 'historic_eligible',
            ...$replay->over($runs),
        ];

        if ($this->option('json')) {
            $this->line(CanonicalJson::encode($report));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        /** @var array<string, array<string, mixed>> $detectors */
        $detectors = $report['detectors'];
        /** @var array<string, int> $coverage */
        $coverage = $report['coverage'];
        /** @var array<string, int> $uncatalogued */
        $uncatalogued = $report['uncatalogued_signals'];

        $this->components->info(sprintf(
            '%s population: %d runs read',
            $report['population'],
            $report['runs_read'],
        ));

        $signals = array_sum(array_column($detectors, 'signals'));
        $unheld = array_sum(array_column($detectors, 'unheld'));
        $silent = array_filter($detectors, static fn (array $row): bool => $row['silent'] === true);

        $this->table(['Measure', 'Value'], [
            ['Signals recorded', (string) $signals],
            ['Held', (string) array_sum(array_column($detectors, 'held'))],
            ['Unheld', (string) $unheld],
            ['Promoted detectors silent across the population', (string) count($silent)],
            ['Transcript: screened / never screened', $coverage['transcript_screened'].' / '.$coverage['transcript_unscreened']],
            ['Video: assessed / never assessed', $coverage['video_assessed'].' / '.$coverage['video_unassessed']],
            ['Uncatalogued signal kinds', (string) count($uncatalogued)],
            ['Non-detector flags (operator holds, restatements)', (string) array_sum($report['non_detector_flags'])],
        ]);

        if ($this->option('details')) {
            $rows = [];

            foreach ($detectors as $id => $row) {
                $rows[] = [
                    $id,
                    (string) $row['severity'],
                    (string) $row['signals'],
                    (string) $row['held'],
                    (string) $row['unheld'],
                    (string) $row['runs'],
                    $row['silent'] ? 'silent' : '',
                ];
            }

            $this->table(['Detector', 'Sev', 'Signals', 'Held', 'Unheld', 'Runs', ''], $rows);
        }

        // Silence only means something over a whole population. On a named
        // subset a detector is silent because the runs were not chosen for it,
        // which says nothing about the detector.
        if ($silent !== [] && (array) $this->option('run') === []) {
            $this->components->warn(
                'Silent detectors (no signal anywhere in this population; either the class does not occur '
                .'or the detector has stopped working, and this report cannot tell those apart): '
                .implode(', ', array_keys($silent))
            );
        }

        if ($uncatalogued !== []) {
            $this->components->warn('Stored signals no catalogue entry claims:');

            foreach ($uncatalogued as $key => $count) {
                $this->line(sprintf('  %6d  %s', $count, $key));
            }
        }
    }

    /**
     * @return list<MediaProcessingLog>
     */
    private function population(): array
    {
        /** @var list<string> $runIds */
        $runIds = (array) $this->option('run');

        if ($runIds !== []) {
            $wanted = array_map('intval', $runIds);
            $found = MediaProcessingLog::query()->whereIn('id', $wanted)->orderBy('id')->get();
            $missing = array_values(array_diff($wanted, $found->pluck('id')->all()));

            if ($missing !== []) {
                // Named runs that cannot be found are an error, never an empty
                // pass: a run is invisible whenever the app is pointed at
                // another database, which is what `artisan dusk` does for the
                // length of its suite.
                throw new RuntimeException(
                    'These runs could not be found: '.implode(', ', $missing)
                    .'. Check nothing has repointed the database, such as a Dusk run in progress.'
                );
            }

            return array_values($found->all());
        }

        if ($this->option('weekly')) {
            return array_values(MediaProcessingLog::query()
                ->whereNull('historic_import_operation_id')
                ->where('status', 'completed')
                ->orderBy('id')
                ->get()
                ->all());
        }

        // The default and `--all` are the same population: excluding a run is a
        // terminal operator decision, so an excluded run is not eligible and is
        // filtered in PHP because the reason lives in processing metadata.
        return array_values(MediaProcessingLog::query()
            ->whereNotNull('historic_import_operation_id')
            ->where('status', 'completed')
            ->orderBy('id')
            ->get()
            ->reject(static fn (MediaProcessingLog $run): bool => $run->isExcluded())
            ->all());
    }
}
