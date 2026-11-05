<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Support\SermonAssetReferences;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only check that every completed historic run's sermon serves the main video the run made.
 *
 * A run records its main video's size and SHA-256 in `media_outputs.sermon` when it cuts it, and
 * promotion moves that file to quarantine. On 11-02 ten section-failure repairs closed their runs
 * without promoting, so quarantine kept each main video's previous generation while the runs read as
 * completed (1025, 1055, 1133, 1199, 1214, 1286, 1299, 1305, 1325, 1349). Size alone found all ten;
 * `--hash` also reads every file, for two cuts of one length.
 *
 * A staging sermon path is joined to its run's batch root here, read-only, rather than by entering
 * the staging context: a rolled-back replay inside one deleted 126 real clips on 10-29.
 *
 * Deletion trigger: Delete once the historic archive is fully released and quarantine is retired,
 * alongside the other historic-import one-shots.
 */
class VerifyHistoricOutputGenerationsCommand extends Command
{
    protected $signature = 'historic-import:verify-output-generations
        {--hash : Also compare every served video\'s SHA-256, not only its size (reads every file)}
        {--json : Emit the report as JSON}';

    protected $description = 'Read-only check that each completed historic run\'s sermon serves the main video the run recorded';

    public function handle(): int
    {
        $report = ['checked' => 0, 'current' => 0, 'stale' => [], 'missing' => [], 'check_errors' => []];
        $hash = (bool) $this->option('hash');

        $runs = MediaProcessingLog::query()
            ->select(['id', 'sermon_id', 'processing_metadata'])
            ->whereNotNull('historic_import_operation_id')
            ->whereNotNull('sermon_id')
            ->whereNull('superseded_at')
            ->where('status', ProcessingStatus::Completed)
            ->orderBy('id')
            ->cursor();

        foreach ($runs as $log) {
            $recorded = data_get($log->processing_metadata?->toArray(), 'media_outputs.sermon');
            $sermon = Sermon::query()->select(['id', 'asset_disk', 'video_file_path'])->find($log->sermon_id);

            if (! is_array($recorded) || ! isset($recorded['size'], $recorded['sha256']) || ! filled($sermon?->video_file_path)) {
                continue;
            }

            $report['checked']++;
            $outcome = $this->outcome($log, $sermon, (int) $recorded['size'], (string) $recorded['sha256'], $hash);

            if ($outcome === 'current') {
                $report['current']++;
            } else {
                $report[$outcome][] = $log->id;
            }
        }

        $failed = $report['stale'] !== [] || $report['missing'] !== [] || $report['check_errors'] !== [];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['Checked', 'Current', 'Stale', 'Missing', 'Check errors'], [[
            $report['checked'], $report['current'], count($report['stale']), count($report['missing']), count($report['check_errors']),
        ]]);

        foreach (['stale', 'missing', 'check_errors'] as $kind) {
            if ($report[$kind] !== []) {
                $this->line(ucfirst(str_replace('_', ' ', $kind)).' runs: '.implode(', ', $report[$kind]));
            }
        }

        if (! $failed) {
            $this->info('Every completed historic run serves the main video it recorded'.($hash ? ', byte for byte.' : ' (by size; --hash reads every file).'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return 'current'|'stale'|'missing'|'check_errors'
     */
    private function outcome(MediaProcessingLog $log, Sermon $sermon, int $size, string $sha256, bool $hash): string
    {
        [$disk, $path] = $this->location($log, $sermon);

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                return 'missing';
            }

            if ($storage->size($path) !== $size) {
                return 'stale';
            }

            if (! $hash) {
                return 'current';
            }

            $stream = $storage->readStream($path);

            if (! is_resource($stream)) {
                return 'check_errors';
            }

            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);

            return hash_final($context) === $sha256 ? 'current' : 'stale';
        } catch (Throwable) {
            return 'check_errors';
        }
    }

    /** @return array{0: string, 1: string} */
    private function location(MediaProcessingLog $log, Sermon $sermon): array
    {
        $disk = $sermon->assetDisk(SermonAssetReferences::sermonDisk());
        $path = (string) $sermon->video_file_path;
        $context = $disk === 'historic_staging' ? $log->historicStagingContext() : null;

        return $context instanceof HistoricStagingContext
            ? [$context->stagingDisk, rtrim($context->batchRoot, '/').'/'.ltrim($path, '/')]
            : [$disk, $path];
    }
}
