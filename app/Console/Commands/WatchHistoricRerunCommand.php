<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricRerunWatch;
use App\Support\PrivateEvidenceFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Watch a running corpus re-run and pause every worker queue on a stopping condition
 * (plan VIDEO-BOUNDARY-CONSISTENCY §6.3.9), so the full pass can run unattended.
 *
 * Runs without Claude: start it beside the workers and it looks every `--interval` seconds.
 * Each look overwrites `watch-status.json` beside the snapshot, which is what progress reports
 * read. On a stop it pauses the queues, writes `watch-stop.json` and exits non-zero; resume
 * with `queue:continue` once the cause is fixed. `--stop` raises a stop no machine can see — wrong on
 * listening — through the same door. `watch-state.json` keeps when the watch began,
 * so a restarted watch does not forget the failed jobs and missing files it has already seen;
 * its `acknowledged` stops are reported as warnings, and its `parked` runs (jobs pulled on
 * purpose) are counted apart from those still processing.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class WatchHistoricRerunCommand extends Command
{
    protected $signature = 'historic-import:rerun-watch
        {snapshot : The before-state written by historic-import:rerun-snapshot}
        {--interval=300 : Seconds between looks}
        {--once : Look once and exit}
        {--min-free-gb=10 : Stop when the internal disk holding service artifacts has less free than this}
        {--stop= : Stop the pass now for this reason (a listening verdict), as a stopping condition would}';

    protected $description = 'Watch a corpus re-run and pause every worker queue on a stopping condition';

    public function handle(HistoricRerunWatch $watch): int
    {
        try {
            $snapshotPath = PrivateEvidenceFile::resolve($this->argument('snapshot'), 'The re-run snapshot');
            $snapshot = HistoricRerunSnapshot::fromFile($snapshotPath);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $interval = max(1, (int) $this->option('interval'));
        $minimumFree = (float) $this->option('min-free-gb');
        $directory = dirname($snapshotPath);
        $state = $this->state($watch, $snapshot, $directory.'/watch-state.json');
        $raised = $this->option('stop');

        while (true) {
            $result = is_string($raised) && $raised !== ''
                ? ['stops' => [$raised], 'warnings' => [], 'progress' => ['checked_at' => now()->toIso8601String()]]
                : $watch->check($snapshot, $state, $minimumFree);

            if (! is_string($raised) || $raised === '') {
                $this->write($directory.'/watch-status.json', $result);
            }

            if ($result['stops'] !== []) {
                $paused = $watch->pauseWorkers();
                $this->write($directory.'/watch-stop.json', [...$result, 'paused' => $paused]);
                Log::error('Corpus re-run watch stopped the pass', ['stops' => $result['stops'], 'paused' => $paused]);

                foreach ($result['stops'] as $stop) {
                    $this->components->error($stop);
                }
                $this->components->warn('Paused '.implode(', ', $paused).'; resume each with queue:continue once fixed.');

                return self::FAILURE;
            }

            $stages = [];
            foreach ((array) $result['progress']['runs'] as $stage => $count) {
                $stages[] = "{$stage} {$count}";
            }

            $this->components->info(sprintf(
                '%s: no stop; %s; %d warning(s)',
                $result['progress']['checked_at'],
                implode(', ', $stages),
                count($result['warnings']),
            ));

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            sleep($interval);
        }
    }

    /**
     * @return array{started_at: string, missing_at_start: list<string>, acknowledged?: list<string>, parked?: list<int>}
     */
    private function state(HistoricRerunWatch $watch, HistoricRerunSnapshot $snapshot, string $path): array
    {
        $stored = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (is_array($stored) && ($stored['membership_sha256'] ?? null) === $snapshot->membershipSha256) {
            return [
                'started_at' => (string) $stored['started_at'],
                'missing_at_start' => array_values((array) $stored['missing_at_start']),
                'acknowledged' => array_values(array_filter((array) ($stored['acknowledged'] ?? []), 'is_string')),
                'parked' => array_values(array_filter((array) ($stored['parked'] ?? []), 'is_int')),
            ];
        }

        $state = $watch->begin($snapshot);
        $this->write($path, [...$state, 'membership_sha256' => $snapshot->membershipSha256]);

        return $state;
    }

    /** @param  array<string, mixed>  $contents */
    private function write(string $path, array $contents): void
    {
        $temporary = $path.'.tmp';
        file_put_contents($temporary, json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($temporary, $path);
    }
}
