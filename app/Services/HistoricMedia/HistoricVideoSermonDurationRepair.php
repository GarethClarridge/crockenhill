<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\SermonSourceType;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Repair stale sermon durations after a historic re-extraction has already
 * completed and its source working copy has been reclaimed.
 *
 * Deletion trigger: Delete after IC8 closeout once the Phase 7 duration repair
 * and its retained evidence are complete.
 */
final class HistoricVideoSermonDurationRepair
{
    /**
     * @param  list<string>  $processingIds
     * @return list<array{
     *     processing_id: string,
     *     sermon: Sermon,
     *     current_duration: float|null,
     *     repaired_duration: float,
     *     disposition: 'pending'|'already_repaired'
     * }>
     */
    public function inspect(HistoricImportOperation $operation, array $processingIds): array
    {
        if ($processingIds === [] || count($processingIds) !== count(array_unique($processingIds))) {
            throw new RuntimeException('Name every target processing ID exactly once.');
        }

        $runs = MediaProcessingLog::query()
            ->whereIn('processing_id', $processingIds)
            ->get()
            ->keyBy('processing_id');
        $missing = array_values(array_diff($processingIds, $runs->keys()->all()));

        if ($missing !== []) {
            throw new RuntimeException('Selected processing runs do not exist: '.implode(', ', $missing).'.');
        }

        $entries = [];

        foreach ($processingIds as $processingId) {
            $run = $runs->get($processingId);

            if (! $run instanceof MediaProcessingLog) {
                throw new RuntimeException("Processing run {$processingId} could not be loaded.");
            }

            $this->assertRunOwnership($run, $operation);
            $sermon = $this->sermonForRun($run, $operation);
            $duration = $run->extractedSermonMediaDuration();

            if ($duration === null || $duration <= 0) {
                throw new RuntimeException("Processing run {$processingId} has no positive recorded extraction duration.");
            }

            $currentDuration = $sermon->duration === null ? null : (float) $sermon->duration;

            $entries[] = [
                'processing_id' => $processingId,
                'sermon' => $sermon,
                'current_duration' => $currentDuration,
                'repaired_duration' => $duration,
                'disposition' => $currentDuration !== null && abs($currentDuration - $duration) < 0.001
                    ? 'already_repaired'
                    : 'pending',
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{
     *     processing_id: string,
     *     sermon: Sermon,
     *     current_duration: float|null,
     *     repaired_duration: float,
     *     disposition: 'pending'|'already_repaired'
     * }>  $entries
     * @return array{repaired: int, already_repaired: int}
     */
    public function apply(HistoricImportOperation $operation, array $entries): array
    {
        return DB::transaction(function () use ($operation, $entries): array {
            $repaired = 0;
            $alreadyRepaired = 0;

            foreach ($entries as $entry) {
                $run = MediaProcessingLog::query()
                    ->where('processing_id', $entry['processing_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $run instanceof MediaProcessingLog) {
                    throw new RuntimeException("Processing run {$entry['processing_id']} disappeared before duration repair.");
                }

                $this->assertRunOwnership($run, $operation);
                $repairedDuration = $run->extractedSermonMediaDuration();

                if ($repairedDuration === null || $repairedDuration <= 0) {
                    throw new RuntimeException("Processing run {$run->processing_id} has no positive recorded extraction duration.");
                }

                $sermon = Sermon::query()->whereKey($entry['sermon']->id)->lockForUpdate()->first();

                if (! $sermon instanceof Sermon) {
                    throw new RuntimeException("Sermon {$entry['sermon']->id} disappeared before duration repair.");
                }

                if ($run->sermon_id !== null && $run->sermon_id !== $sermon->id) {
                    throw new RuntimeException("Processing run {$run->processing_id} has an inconsistent sermon link.");
                }

                if ($sermon->livestream_processing_id !== $run->processing_id) {
                    throw new RuntimeException("Sermon {$sermon->id} is not owned by processing run {$run->processing_id}.");
                }

                $this->assertSermonOwnership($sermon, $operation);

                if ($sermon->duration !== null
                    && abs((float) $sermon->duration - $repairedDuration) < 0.001) {
                    $alreadyRepaired++;

                    continue;
                }

                $sermon->forceFill(['duration' => $repairedDuration])->save();
                $repaired++;
            }

            return ['repaired' => $repaired, 'already_repaired' => $alreadyRepaired];
        });
    }

    private function assertRunOwnership(MediaProcessingLog $run, HistoricImportOperation $operation): void
    {
        if ($run->status !== ProcessingStatus::Completed || $run->processing_type !== MediaType::Livestream) {
            throw new RuntimeException("Processing run {$run->processing_id} must be a completed livestream run.");
        }

        if ($run->historic_import_operation_id !== $operation->id
            || data_get($run->processing_metadata?->toArray(), 'historic_import.operation_id') !== $operation->operation_id
            || $run->historicImportJobKey() === null) {
            throw new RuntimeException("Processing run {$run->processing_id} does not belong to the named historic operation.");
        }
    }

    private function sermonForRun(MediaProcessingLog $run, HistoricImportOperation $operation): Sermon
    {
        $sermons = Sermon::query()
            ->where('livestream_processing_id', $run->processing_id)
            ->orderBy('id')
            ->get();

        if ($sermons->count() !== 1 || ! $sermons->first() instanceof Sermon) {
            throw new RuntimeException("Processing run {$run->processing_id} must identify exactly one sermon.");
        }

        $sermon = $sermons->first();

        if ($run->sermon_id !== null && $run->sermon_id !== $sermon->id) {
            throw new RuntimeException("Processing run {$run->processing_id} has an inconsistent sermon link.");
        }

        $this->assertSermonOwnership($sermon, $operation);

        return $sermon;
    }

    private function assertSermonOwnership(Sermon $sermon, HistoricImportOperation $operation): void
    {
        $quarantineDisk = (string) config('media-processing.storage.historic_quarantine_disk');

        if ($sermon->source_type !== SermonSourceType::Livestream
            || $sermon->publication_state !== SermonPublicationState::Quarantined
            || $sermon->asset_disk !== $quarantineDisk
            || $sermon->historic_import_operation_id !== $operation->id) {
            throw new RuntimeException("Sermon {$sermon->id} is not private media owned by the named historic operation.");
        }
    }
}
