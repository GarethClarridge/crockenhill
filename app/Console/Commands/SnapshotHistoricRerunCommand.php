<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricRerunState;
use App\Services\HistoricMedia\ListeningRouting;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Record the before-state of a corpus re-run batch (plan §4.0).
 *
 * Read-only. Writes one create-once private file that is both the batch's baseline and its
 * frozen membership: `historic-import:rerun-diff` compares against it, and
 * `historic-import:rerun-redetect` dispatches only the runs it holds. Membership is always
 * explicit; there is no "all". With `--routing`, it also freezes the members' H10b listening
 * routes, which send `new_better` runs to Tier A and keep Tier B from taking them.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class SnapshotHistoricRerunCommand extends Command
{
    protected $signature = 'historic-import:rerun-snapshot
        {runs?* : Exact media processing log IDs}
        {--runs-file= : A file of run IDs separated by whitespace or commas, instead of or as well as the arguments}
        {--output= : New private snapshot path below storage/app/private}
        {--routing= : The H10b listening routing file below storage/app/private, whose new_better routes are Tier A grounds}
        {--routing-sha256= : The sha256 the routing file was scored under}';

    protected $description = 'Capture the before-state of exact historic runs for a corpus re-run diff (writes nothing to the runs)';

    public function handle(HistoricRerunState $state): int
    {
        try {
            $path = PrivateEvidenceFile::resolve($this->option('output'), 'The re-run snapshot');
            $routing = $this->option('routing');
            $listening = $routing === null
                ? null
                : ListeningRouting::fromFile(PrivateEvidenceFile::resolve($routing, 'The listening routing'), $this->option('routing-sha256'));
            $snapshot = HistoricRerunSnapshot::take($this->runIds(), $state, $listening);

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

        if ($snapshot->listening !== null) {
            $this->line(sprintf('Listening routes for %d member(s) from routing %s.', count($snapshot->listening->runs), $snapshot->listening->fileSha256));
        }
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
