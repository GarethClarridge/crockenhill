<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\LivestreamSegment;
use App\Enums\LivestreamSegmentClassification;
use App\Jobs\ExtractSermon;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Media\Video\VideoExtractionService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Re-derive the sermon audio a historic run produced and a later cleanup removed.
 *
 * The pilot cohort's sermon videos all survive; their audio does not, because
 * promotion and cleanup reclaimed it while it was still referenced. Custody
 * repair needs both, so it fails closed on every pilot run.
 *
 * Audio is a derivative, not evidence: the cut video is the durable artifact and
 * the audio is the same span encoded for speech. Re-deriving it from that video
 * is exactly what {@see ExtractSermon} does on its concatenated branch,
 * through the same {@see VideoExtractionService::extractOptimizedAudio()} call
 * and the same transcription audio profile, so nothing about the durable output
 * fingerprint changes.
 *
 * Deletion trigger: Delete once the historic pilot cohort's custody is repaired
 * and the historic import's closeout retention window has expired.
 */
final class HistoricSermonAudioRegeneration
{
    public function __construct(
        private readonly VideoExtractionService $videoExtractor,
        private readonly ExtractedMediaDurationProbe $durationProbe,
    ) {}

    /**
     * @param  list<string>  $processingIds
     * @return list<array{
     *     processing_id: string,
     *     sermon: Sermon,
     *     disk: string,
     *     video_path: string,
     *     audio_path: string,
     *     video_duration: float,
     *     disposition: 'pending'|'already_present'
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

            if ($run->historic_import_operation_id !== $operation->id) {
                throw new RuntimeException("Processing run {$processingId} is not owned by operation {$operation->operation_id}.");
            }

            $sermon = $this->sermonForRun($run);
            $location = $this->assetLocation($run, $sermon);
            $disk = Storage::disk($location['disk']);

            if (! $disk->exists($location['video_path'])) {
                throw new RuntimeException(
                    "Sermon {$sermon->id} video is missing from disk {$location['disk']}: {$location['video_path']}",
                );
            }

            $entries[] = [
                'processing_id' => $processingId,
                'sermon' => $sermon,
                'disk' => $location['disk'],
                'video_path' => $location['video_path'],
                'audio_path' => $location['audio_path'],
                'video_duration' => $this->durationProbe->durationOf($disk->path($location['video_path'])),
                'disposition' => $disk->exists($location['audio_path']) ? 'already_present' : 'pending',
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{
     *     processing_id: string,
     *     sermon: Sermon,
     *     disk: string,
     *     video_path: string,
     *     audio_path: string,
     *     video_duration: float,
     *     disposition: 'pending'|'already_present'
     * }>  $entries
     * @return array{regenerated: int, already_present: int, bytes: int}
     */
    public function apply(array $entries): array
    {
        $regenerated = 0;
        $alreadyPresent = 0;
        $bytes = 0;

        foreach ($entries as $entry) {
            $disk = Storage::disk($entry['disk']);

            if ($disk->exists($entry['audio_path'])) {
                $alreadyPresent++;

                continue;
            }

            $this->videoExtractor->extractOptimizedAudio(
                $disk->path($entry['video_path']),
                $this->wholeAssetSegment($entry['video_duration']),
                basename($entry['audio_path']),
                $entry['disk'],
                dirname($entry['audio_path']),
            );

            // Sizing the result is the verification: the historic disks are
            // configured to throw, so audio the extractor claimed to write but
            // did not surfaces here rather than counting as zero bytes.
            $writtenBytes = (int) $disk->size($entry['audio_path']);

            if ($writtenBytes <= 0) {
                throw new RuntimeException(
                    "Audio regeneration reported success but produced an empty file: {$entry['audio_path']}",
                );
            }

            $bytes += $writtenBytes;
            $regenerated++;
        }

        return ['regenerated' => $regenerated, 'already_present' => $alreadyPresent, 'bytes' => $bytes];
    }

    /**
     * The cut video is already exactly the sermon, so the audio span is all of it.
     */
    private function wholeAssetSegment(float $duration): LivestreamSegment
    {
        return new LivestreamSegment(
            startTime: 0.0,
            endTime: $duration,
            duration: $duration,
            classification: LivestreamSegmentClassification::Speech->value,
            avgRms: 0.0,
            peakRms: 0.0,
            isSermonCandidate: true,
            segmentOrder: 0,
        );
    }

    /**
     * @return array{disk: string, video_path: string, audio_path: string}
     */
    private function assetLocation(MediaProcessingLog $run, Sermon $sermon): array
    {
        $videoPath = $sermon->video_file_path;
        $audioPath = $sermon->audio_file_path;

        if (! is_string($videoPath) || $videoPath === '' || ! is_string($audioPath) || $audioPath === '') {
            throw new RuntimeException("Sermon {$sermon->id} does not record both a video and an audio path.");
        }

        $assetDisk = (string) ($sermon->asset_disk ?? '');

        if ($assetDisk !== '') {
            return ['disk' => $assetDisk, 'video_path' => $videoPath, 'audio_path' => $audioPath];
        }

        $context = $run->historicStagingContext();

        if ($context === null) {
            throw new RuntimeException(
                "Processing run {$run->processing_id} has no asset disk and no staging context to resolve its assets.",
            );
        }

        $batchRoot = rtrim($context->batchRoot, '/');

        return [
            'disk' => $context->stagingDisk,
            'video_path' => $batchRoot.'/'.ltrim($videoPath, '/'),
            'audio_path' => $batchRoot.'/'.ltrim($audioPath, '/'),
        ];
    }

    private function sermonForRun(MediaProcessingLog $run): Sermon
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

        return $sermon;
    }
}
