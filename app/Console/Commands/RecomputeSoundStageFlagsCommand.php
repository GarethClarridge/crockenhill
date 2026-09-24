<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Services\DetectorEvaluation\SoundStageFlagRecompute;
use App\Services\DetectorEvaluation\SoundStageFlagWriter;
use App\Support\CanonicalJson;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Re-derive the sound-stage review flags from banked structure and, with
 * `--apply`, write them onto the sections that already exist.
 *
 * §4.3a H7b found three promoted detectors that had never been applied to the
 * corpus — `structure_song_widened_to_sustained_sound`,
 * `structure_unidentified_singing` and `structure_section_reads_as_sung`. They
 * postdate the detection pass that would have written them, and 436 of the 443
 * eligible runs have not been re-detected since.
 *
 * **Re-detection is the wrong remedy and this is the right one.** These three
 * flags are not the LLM detector's: they come from two passes that run over the
 * structure it produced, plus the RMS log and the transcript, and both are pure
 * functions of those inputs. Re-running detection instead would cost 443
 * provider calls, replace every projected section, and — being
 * non-deterministic — rewrite months of adjudicated boundaries with no baseline
 * to say which runs came back worse.
 *
 * **Dry run by default.** `--apply` is the only thing that writes, and the dry
 * run reports exactly what it would do. That order is not ceremony: the
 * 2026-09-21 screen application forecast 37 new holds, raised 71, and withdrew 5
 * nobody had predicted at all.
 *
 * **Inserts are deferred, never written.** See {@see SoundStageFlagWriter} for
 * why — in short, a section inserted mid-structure shifts every later section
 * into a different signature comparison and deletes its extracted media.
 */
class RecomputeSoundStageFlagsCommand extends Command
{
    protected $signature = 'structure:recompute-sound-stage
        {--run=* : Processing run ids to recompute}
        {--all : Every eligible completed historic run}
        {--apply : Write the flags (default is a dry run that writes nothing)}
        {--json : Emit the whole report as JSON}';

    protected $description = 'Re-derive sound-stage review flags from banked structure (dry run unless --apply)';

    public function handle(SoundStageFlagRecompute $recompute, SoundStageFlagWriter $writer): int
    {
        ['eligible' => $runs, 'skipped' => $skipped] = $this->population();

        if ($runs === [] && $skipped === []) {
            $this->components->error('No runs matched. Pass --all or --run=.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('apply');

        $scan = $recompute->over($runs);
        $write = $writer->apply($scan['affected_sections'], $execute);

        $report = [
            'population' => count($runs),
            'skipped_ineligible_run_ids' => $skipped,
            'runs_assessed' => $scan['runs_assessed'],
            'runs_unassessable' => $scan['runs_unassessable'],
            'unassessable_run_ids' => $scan['unassessable_run_ids'],
            'sections_gaining_flag' => $scan['sections_gaining_flag'],
            ...$write,
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
        $this->components->info(sprintf(
            '%s: %d runs assessed, %d unassessable',
            $report['executed'] ? 'APPLIED' : 'Dry run (nothing written)',
            $report['runs_assessed'],
            $report['runs_unassessable'],
        ));

        if ($report['skipped_ineligible_run_ids'] !== []) {
            $this->components->warn(sprintf(
                'Skipped runs %s: excluded or not completed, as --all would skip them.',
                implode(', ', $report['skipped_ineligible_run_ids']),
            ));
        }

        $this->table(['Measure', 'Value'], [
            ['Sections written', (string) $report['sections_written']],
            ['Of those, newly held', (string) $report['sections_newly_held']],
            ['Already carried the flag', (string) $report['sections_already_correct']],
            ['Deferred inserts (proposed sections, never written)', (string) count($report['deferred_inserts'])],
            ['Refused on bounds mismatch', (string) count($report['bounds_mismatch'])],
        ]);

        if ($report['deferred_inserts'] !== []) {
            $runs = array_values(array_unique(array_map(
                static fn (array $entry): int => $entry['run'],
                $report['deferred_inserts'],
            )));
            sort($runs);

            $this->components->warn(sprintf(
                '%d findings are sections the sound stage would propose, not flags on existing ones, '
                .'and are NOT written: runs %s. Inserting them shifts every later section into a '
                .'different signature comparison and deletes its extracted media.',
                count($report['deferred_inserts']),
                implode(', ', $runs),
            ));
        }

        if ($report['bounds_mismatch'] !== []) {
            $this->components->warn(
                'Some sections were refused because the stored bounds disagree with the structure '
                .'position they were matched to; writing them could have flagged the wrong section.'
            );
        }

        if (! $report['executed'] && $report['sections_written'] > 0) {
            $this->components->info('Re-run with --apply to write these.');
        }
    }

    /**
     * The runs to recompute, and the named runs left out.
     *
     * Named runs obey the same eligibility as `--all`. They did not once: 1089, excluded as a
     * rehearsal duplicate, was flagged by a named pass and not by `--all`, which read as a
     * harness defect until the exclusion was found.
     *
     * @return array{eligible: list<MediaProcessingLog>, skipped: list<int>}
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
                throw new RuntimeException(
                    'These runs could not be found: '.implode(', ', $missing)
                    .'. Check nothing has repointed the database, such as a Dusk run in progress.'
                );
            }

            return [
                'eligible' => array_values($found->filter(fn (MediaProcessingLog $run): bool => $this->isEligible($run))->all()),
                'skipped' => array_values($found
                    ->reject(fn (MediaProcessingLog $run): bool => $this->isEligible($run))
                    ->map(static fn (MediaProcessingLog $run): int => (int) $run->id)
                    ->all()),
            ];
        }

        if (! $this->option('all')) {
            return ['eligible' => [], 'skipped' => []];
        }

        return [
            'eligible' => array_values(MediaProcessingLog::query()
                ->whereNotNull('historic_import_operation_id')
                ->where('status', 'completed')
                ->orderBy('id')
                ->get()
                ->filter(fn (MediaProcessingLog $run): bool => $this->isEligible($run))
                ->all()),
            'skipped' => [],
        ];
    }

    private function isEligible(MediaProcessingLog $run): bool
    {
        return $run->historic_import_operation_id !== null
            && $run->status === ProcessingStatus::Completed
            && ! $run->isExcluded();
    }
}
