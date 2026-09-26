<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BackfillAudioTimeline;
use App\Models\MediaProcessingLog;
use Illuminate\Console\Command;

/**
 * Backfill the audio timeline for existing historic runs (music and silence plan §6.7).
 *
 * Structure detection refuses a run without a timeline, and the corpus re-run re-detects every
 * eligible run, so each needs one before its snapshot is taken. Runs synchronously in the app
 * container on CPU, as the pipeline job does: ~12 minutes a 75-minute service. Create-once, so
 * re-running it continues where it stopped; `--max` bounds one invocation.
 *
 * The dry run (the default) reports what each run would need: already done, classify, re-attach
 * its orphaned audio then classify, or refused with the reason. Resolve or exclude the refusals
 * before `--execute`; none is ever classified from a different file.
 *
 * Delete once every eligible historic run has a timeline and canary 5 has run on it.
 */
class ClassifyHistoricAudioCommand extends Command
{
    protected $signature = 'historic-import:classify-audio
        {runs?* : Run ids; every completed, unexcluded historic run when omitted}
        {--max=10 : The most runs one invocation will classify}
        {--execute : Classify; without this option the command is a dry run}';

    protected $description = 'Record the music/speech audio timeline for existing historic runs';

    public function handle(BackfillAudioTimeline $backfill): int
    {
        $execute = (bool) $this->option('execute');
        $max = (int) $this->option('max');

        if ($max < 1) {
            $this->components->error('--max must be at least 1.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn('DRY RUN: nothing will be written.');
        }

        $rows = [];
        $counts = [];
        $worked = 0;

        foreach ($this->runs() as $run) {
            if ($worked >= $max) {
                $rows[] = [$run->id, 'not reached', sprintf('--max=%d reached', $max)];
                $counts['not reached'] = ($counts['not reached'] ?? 0) + 1;

                continue;
            }

            $result = $backfill->execute($run, $execute);
            $counts[$result['outcome']] = ($counts[$result['outcome']] ?? 0) + 1;
            $rows[] = [$run->id, $result['outcome'], $result['reason']];

            if (! in_array($result['outcome'], ['done', 'refused'], true)) {
                $worked++;
            }
        }

        $this->table(['Run', 'Outcome', 'Reason'], $rows);
        ksort($counts);
        $this->line(implode(', ', array_map(static fn (string $outcome, int $count): string => "{$count} {$outcome}", array_keys($counts), $counts)) ?: 'No runs.');

        return ($counts['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return iterable<MediaProcessingLog>
     */
    private function runs(): iterable
    {
        $ids = array_map('intval', (array) $this->argument('runs'));

        if ($ids !== []) {
            return MediaProcessingLog::query()->whereKey($ids)->orderBy('id')->get();
        }

        return MediaProcessingLog::query()
            ->completed()
            ->notSuperseded()
            ->whereNotNull('historic_import_operation_id')
            ->orderBy('id')
            ->get()
            ->reject(static fn (MediaProcessingLog $run): bool => $run->isExcluded());
    }
}
