<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-cut a finished run's sermon from the structure it already has.
 *
 * Confirming or correcting a service's structure moves where the sermon starts
 * and ends, so the published audio has to be rebuilt. Re-running the whole
 * pipeline would re-transcribe and re-detect to get there — expensive, and the
 * detector is not deterministic across passes, so it can answer differently and
 * undo the correction being published. This resumes at the extraction phase
 * instead, keeping the sections, transcript and RMS log exactly as they are.
 */
class ReExtractSermonCommand extends Command
{
    protected $signature = 'sermons:re-extract
                            {processing_id : The processing ID of the run to re-cut}
                            {--dry-run : Report the span change without dispatching}';

    protected $description = "Re-cut a finished run's sermon from its existing service structure";

    public function handle(
        SermonExtractionPlanResolver $planResolver,
        ProcessingRunOrchestrator $orchestrator,
        HistoricStagingContextRegistry $stagingContextRegistry,
    ): int {
        $processingId = (string) $this->argument('processing_id');

        $processingLog = MediaProcessingLog::query()
            ->where('processing_id', $processingId)
            ->first();

        if (! $processingLog instanceof MediaProcessingLog) {
            $this->error("No processing run found for {$processingId}.");

            return self::FAILURE;
        }

        if ($processingLog->serviceSections()->count() === 0) {
            $this->error('This run has no service sections, so there is no structure to re-cut from.');

            return self::FAILURE;
        }

        $stagingContext = $processingLog->historicStagingContext();
        $inspect = fn (callable $callback): mixed => $stagingContext === null
            ? $callback()
            : $stagingContextRegistry->within($stagingContext, \Closure::fromCallable($callback));

        /** @var array{mode: string, segments: array<int, array{start_time: float, end_time: float}>, metadata: array<string, mixed>} $plan */
        $plan = $inspect(fn (): array => $planResolver->resolve($processingLog));
        $segments = $plan['segments'];

        if ($segments === []) {
            $this->error('The extraction plan resolved no segments; nothing would be published.');

            return self::FAILURE;
        }

        $newStart = (float) $segments[0]['start_time'];
        $newEnd = (float) $segments[array_key_last($segments)]['end_time'];

        $this->line(sprintf('Run:      %s (%s)', $processingId, $processingLog->status->value));
        $this->line(sprintf('Recorded: %.1fs - %.1fs', (float) $processingLog->sermon_start_time, (float) $processingLog->sermon_end_time));
        $this->line(sprintf('Planned:  %.1fs - %.1fs  [%s]', $newStart, $newEnd, (string) ($plan['metadata']['strategy'] ?? 'unknown')));

        $sourcePath = (string) $processingLog->source_file_path;
        $sourceExists = $sourcePath !== '' && (bool) $inspect(
            fn (): bool => Storage::disk((string) config('media-processing.storage.temp_disk'))->exists($sourcePath)
        );

        if (! $sourceExists) {
            // CleanupTemporaryFiles deletes source_file_path when a run completes,
            // so a finished run usually has nothing left to cut from. The media has
            // to be restaged before this can run — for a historic import that means
            // `sermons:import-historic-videos --force --only=<item key>`.
            $this->error("The source media for this run is gone ({$sourcePath}), so it cannot be re-cut.");
            $this->line('Restage the source first, or reprocess the item from its original recording.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing dispatched.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Re-cut and republish this sermon?', false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $result = $orchestrator->reExtract($processingLog);

        if (! $result->success) {
            $this->error($result->message);

            return self::FAILURE;
        }

        $this->info("Re-extraction dispatched for {$processingId}.");

        return self::SUCCESS;
    }
}
