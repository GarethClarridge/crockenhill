<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Support\ServiceArtifactDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-shot: copies surviving legacy full-service transcripts off the temp disk
 * onto the transcript disk, before `media:cleanup-temp-files` sweeps them.
 *
 * Runs completed before WP-A1 recorded a temp-disk-relative
 * `temp/service_transcript_*.json` path, which the daily sweep deletes 24 hours
 * after completion. Readers still resolve those legacy paths, so nothing breaks
 * until the sweep fires — after which the run can never be re-detected without
 * the source recording.
 *
 * Deletion trigger: remove once no processing log carries a temp-disk service
 * transcript path (`sermons:audit-service-transcripts` reports zero).
 */
class MigrateServiceTranscriptsCommand extends Command
{
    protected $signature = 'media:migrate-service-transcripts
                            {--apply : Copy the files (the default is a dry run)}';

    protected $description = 'Copy legacy temp-disk full-service transcripts onto the durable transcript disk';

    public function handle(ServiceArtifactStorage $artifactStorage): int
    {
        $dryRun = ! (bool) $this->option('apply');

        $migrated = 0;
        $alreadyDurable = 0;
        $vanished = 0;
        $failed = 0;

        MediaProcessingLog::query()
            ->whereNotNull('processing_metadata')
            ->orderBy('id')
            ->chunkById(100, function ($logs) use ($artifactStorage, $dryRun, &$migrated, &$alreadyDurable, &$vanished, &$failed): void {
                foreach ($logs as $log) {
                    $path = $log->serviceTranscriptPath();

                    if (! is_string($path) || $path === '') {
                        continue;
                    }

                    if (ServiceArtifactDisk::isDurable($path)) {
                        $alreadyDurable++;

                        continue;
                    }

                    if (! Storage::disk(ServiceArtifactDisk::for($path))->exists($path)) {
                        $vanished++;
                        $this->warn("Already swept, cannot recover: {$log->processing_id} ({$path})");

                        continue;
                    }

                    if ($dryRun) {
                        $migrated++;
                        $this->line("Would migrate {$log->processing_id}: {$path}");

                        continue;
                    }

                    try {
                        $contents = (string) Storage::disk(ServiceArtifactDisk::for($path))->get($path);
                        $decoded = json_decode($contents, true);

                        if (! is_array($decoded)) {
                            throw new \RuntimeException('stored transcript is not valid JSON');
                        }

                        $newPath = $artifactStorage->putJson($log->processing_id, 'normalized', $decoded, [
                            'migrated_from' => $path,
                        ]);

                        $log->putServiceTranscriptPath($newPath);
                        $migrated++;
                        $this->line("Migrated {$log->processing_id}: {$path} → {$newPath}");
                    } catch (\Throwable $throwable) {
                        $failed++;
                        $this->error("Failed {$log->processing_id}: {$throwable->getMessage()}");
                    }
                }
            });

        $this->newLine();
        $this->table(
            ['migrated', 'already durable', 'already swept', 'failed'],
            [[$migrated, $alreadyDurable, $vanished, $failed]],
        );

        if ($dryRun && $migrated > 0) {
            $this->info('Dry run: re-run with --apply to copy these onto the transcript disk.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
