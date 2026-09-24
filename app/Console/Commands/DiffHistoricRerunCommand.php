<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricRerunDiff;
use App\Services\HistoricMedia\HistoricRerunSnapshot;
use App\Services\HistoricMedia\HistoricRerunState;
use App\Support\CanonicalJson;
use App\Support\PrivateEvidenceFile;
use App\Support\RepositoryCommit;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Compare a corpus re-run batch's current state with its snapshot (plan §4.0).
 *
 * Read-only. Re-captures exactly the snapshot's runs, so review reads what changed and
 * why rather than re-examining every run. Changes that can silently lose containment or
 * custody (a live hold gone, a section leaving review or becoming published, extracted
 * media lost, a run left unfinished) are listed separately and make the command fail. After a
 * detection round that deferred its media, the media and review ones are listed as pending
 * extraction instead; the diff after `historic-import:rerun-extract` checks them.
 *
 * Delete once the corpus re-run's batches are accepted, alongside its other instruments.
 */
class DiffHistoricRerunCommand extends Command
{
    protected $signature = 'historic-import:rerun-diff
        {snapshot : The before-state written by historic-import:rerun-snapshot}
        {--output= : New private report path below storage/app/private}';

    protected $description = 'Report what changed on each run since its corpus re-run snapshot (writes nothing to the runs)';

    public function handle(HistoricRerunState $state, HistoricRerunDiff $diff): int
    {
        try {
            $snapshot = HistoricRerunSnapshot::fromFile(PrivateEvidenceFile::resolve($this->argument('snapshot'), 'The re-run snapshot'));
            $outputPath = $this->option('output') === null
                ? null
                : PrivateEvidenceFile::resolve($this->option('output'), 'The re-run diff report');
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $runs = [];
        $kinds = [];
        $needingAttention = 0;
        $pendingExtraction = 0;
        $changed = 0;

        foreach ($snapshot->membership as $runId) {
            $run = MediaProcessingLog::find($runId);
            $result = $diff->compare($snapshot->runs[$runId], $run instanceof MediaProcessingLog ? $state->capture($run) : null);
            $runs[(string) $runId] = $result;

            if ($result['changes'] !== []) {
                $changed++;
            }

            if ($result['attention'] !== []) {
                $needingAttention++;
            }

            if ($result['pending'] !== []) {
                $pendingExtraction++;
            }

            foreach ($result['changes'] as $change) {
                $kind = $change['kind'].(isset($change['flag']) ? ':'.$change['flag'] : '').(array_key_exists('found_by', $change) ? ':'.($change['found_by'] ?? 'unrecorded') : '');
                $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            }
        }

        ksort($kinds);

        $report = [
            'kind' => 'historic_rerun_diff',
            'before' => [
                'taken_at' => $snapshot->takenAt,
                'git_commit' => $snapshot->gitCommit,
                'membership_sha256' => $snapshot->membershipSha256,
                'file_sha256' => $snapshot->fileSha256,
            ],
            'after' => [
                'taken_at' => now()->toIso8601String(),
                'git_commit' => RepositoryCommit::current(),
            ],
            'summary' => [
                'runs' => count($snapshot->membership),
                'runs_changed' => $changed,
                'runs_needing_attention' => $needingAttention,
                'runs_pending_extraction' => $pendingExtraction,
                'changes_by_kind' => $kinds,
            ],
            'runs' => $runs,
        ];

        $this->table(['Change', 'Count'], array_map(static fn (string $kind, int $count): array => [$kind, $count], array_keys($kinds), array_values($kinds)));
        $this->line(sprintf('%d of %d run(s) changed; %d need attention; %d have media custody pending extraction.', $changed, count($snapshot->membership), $needingAttention, $pendingExtraction));

        foreach ($runs as $runId => $result) {
            foreach ($result['attention'] as $item) {
                $this->warn(sprintf('  run #%s: %s', $runId, $item));
            }
        }

        foreach ($runs as $runId => $result) {
            foreach ($result['pending'] as $item) {
                $this->line(sprintf('  run #%s (pending extraction): %s', $runId, $item));
            }
        }

        if ($outputPath !== null) {
            try {
                PrivateEvidenceFile::writeOnce($outputPath, CanonicalJson::encodeReadable($report).PHP_EOL, 'The re-run diff report');
            } catch (RuntimeException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->line('Written to '.$outputPath);
        }

        return $needingAttention === 0 ? self::SUCCESS : self::FAILURE;
    }
}
