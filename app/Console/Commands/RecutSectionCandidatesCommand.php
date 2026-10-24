<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-cut a finished run's section clips (songs and talks) and nothing else.
 *
 * Candidate preparation re-cuts a clip whose media signature has changed and keeps the rest, so
 * this is the cheap way to bring clips up to date with an operator's edges or a moved section.
 * `sermons:re-extract` gets there too, but re-cuts the whole sermon first: on 10-24 that was 82
 * of the 95 minutes nineteen songs' recorded starts took. A published song's clip is refreshed
 * in place.
 */
class RecutSectionCandidatesCommand extends Command
{
    protected $signature = 'sections:recut
                            {processing_ids* : The processing IDs of the runs whose clips to re-cut}
                            {--dry-run : Report what would be dispatched without dispatching}';

    protected $description = "Re-cut a finished run's song and talk clips without re-cutting its sermon";

    public function handle(HistoricStagingContextRegistry $stagingContextRegistry): int
    {
        $refused = 0;

        foreach ((array) $this->argument('processing_ids') as $processingId) {
            $refusal = $this->refusal((string) $processingId, $stagingContextRegistry);

            if ($refusal !== null) {
                $this->error("{$processingId}: {$refusal}");
                $refused++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("{$processingId}: would re-cut its clips.");

                continue;
            }

            PrepareSectionPublicationCandidates::dispatchStandalone(
                MediaProcessingLog::query()->where('processing_id', $processingId)->firstOrFail(),
            );
            $this->info("{$processingId}: re-cut dispatched.");
        }

        return $refused === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function refusal(string $processingId, HistoricStagingContextRegistry $stagingContextRegistry): ?string
    {
        $processingLog = MediaProcessingLog::query()->where('processing_id', $processingId)->first();

        if (! $processingLog instanceof MediaProcessingLog) {
            return 'no processing run has this ID.';
        }

        if ($processingLog->processing_type !== MediaType::Livestream) {
            return 'only a livestream run has section clips.';
        }

        if ($processingLog->status === ProcessingStatus::Processing) {
            return 'the run is still processing; its chain prepares the clips.';
        }

        if ($processingLog->serviceSections()->count() === 0) {
            return 'the run has no service sections.';
        }

        $sourcePath = (string) $processingLog->source_file_path;
        $exists = static fn (): bool => $sourcePath !== ''
            && Storage::disk((string) config('media-processing.storage.temp_disk'))->exists($sourcePath);
        $stagingContext = $processingLog->historicStagingContext();
        $sourceExists = $stagingContext === null ? $exists() : (bool) $stagingContextRegistry->within($stagingContext, $exists);

        if (! $sourceExists) {
            return "the source media is gone ({$sourcePath}); restage it with `historic-import:restage-source` first.";
        }

        return null;
    }
}
