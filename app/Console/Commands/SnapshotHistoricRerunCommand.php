<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricRerunState;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Record the before-state of a corpus re-run batch (plan §4.0).
 *
 * Read-only. Writes one create-once private file that is both the batch's baseline and its
 * frozen membership: `historic-import:rerun-diff` compares against it, and
 * `historic-import:rerun-redetect` dispatches only the runs it holds. Membership is always
 * explicit; there is no "all".
 *
 * Delete with the corpus re-run's other instruments once its batches are accepted.
 */
class SnapshotHistoricRerunCommand extends Command
{
    protected $signature = 'historic-import:rerun-snapshot
        {runs?* : Exact media processing log IDs}
        {--runs-file= : A file of run IDs separated by whitespace or commas, instead of or as well as the arguments}
        {--output= : New private snapshot path below storage/app/private}';

    protected $description = 'Capture the before-state of exact historic runs for a corpus re-run diff (writes nothing to the runs)';

    public function handle(HistoricRerunState $state): int
    {
        try {
            $path = PrivateEvidenceFile::resolve($this->option('output'), 'The re-run snapshot');
            $snapshot = HistoricRerunSnapshot::take($this->runIds(), $state);

            if ($snapshot->membership === []) {
                throw new RuntimeException('Name at least one run.');
            }

            PrivateEvidenceFile::writeOnce($path, $snapshot->encode(), 'The re-run snapshot');
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Captured %d run(s) at %s.', count($snapshot->membership), $snapshot->gitCommit ?? 'an undeterminable commit'));
        $this->line('Membership sha256: '.$snapshot->membershipSha256);
        $this->line('Written to '.$path);

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function runIds(): array
    {
        $ids = (array) $this->argument('runs');
        $file = $this->option('runs-file');

        if (is_string($file) && $file !== '') {
            $contents = is_file($file) ? file_get_contents($file) : false;

            if (! is_string($contents)) {
                throw new RuntimeException("Runs file {$file} could not be read.");
            }

            $ids = [...$ids, ...(preg_split('/[\s,]+/', $contents, -1, PREG_SPLIT_NO_EMPTY) ?: [])];
        }

        return HistoricRerunSnapshot::membership($ids);
    }
}
