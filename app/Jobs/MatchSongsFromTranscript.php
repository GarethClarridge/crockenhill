<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\TranscriptionServiceInterface;
use App\Data\ServiceSectionMetadata;
use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceReviewSynchronizer;
use App\Services\Media\Audio\LocalWhisperTranscriptionService;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\MediaProcessingIdentityResolver;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Song\SongLyricOcrService;
use App\Services\Song\SongLyricsMatchingService;
use App\Services\Song\UnmatchedSongReviewApplicator;
use App\Support\ChurchServiceProcessingTimeline;
use App\Traits\DetectsStorageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MatchSongsFromTranscript extends ProcessingJob implements ShouldQueue
{
    use DetectsStorageType;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1200;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {
        $this->onQueue((string) config('media-processing.queues.audio', 'audio-processing'));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('match-songs-from-transcript-'.$this->processingLog->id))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(
        SongLyricsMatchingService $lyricsMatchingService,
        UnmatchedSongReviewApplicator $unmatchedSongApplicator,
        ChurchServiceReviewSynchronizer $reviewSynchronizer,
        MediaProcessingIdentityResolver $identityResolver,
        VideoExtractionService $videoExtractor,
        StorageAdapterHelper $storageHelper,
        TranscriptionServiceInterface $transcriptionService,
        SongLyricOcrService $ocrService,
        ?LocalWhisperTranscriptionService $localWhisperTranscriptionService = null
    ): void {
        if (! (bool) config('media-processing.song_matching.enabled', true)) {
            $this->initializeStepLogging($this->processingLog->processing_id);
            $this->logStepSkipped(ChurchServiceProcessingTimeline::MATCH_SONGS_FROM_TRANSCRIPT, 'Song matching from transcript disabled');

            return;
        }

        if ($this->refreshAndCheckCancellation($this->processingLog, $this->job ?? null, $this->attempts())) {
            return;
        }

        if ($this->processingLog->processing_type !== MediaType::Livestream) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::MATCH_SONGS_FROM_TRANSCRIPT, 'Song matching only runs for active livestream processing');

            return;
        }

        $this->markProcessingRunAsProcessing($this->processingLog, ProcessingStep::MatchSongsFromTranscript->value);
        $this->logStepStart(ChurchServiceProcessingTimeline::MATCH_SONGS_FROM_TRANSCRIPT);

        /** @var EloquentCollection<int, ServiceSection> $sections */
        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->where('section_type', ServiceSectionType::Song->value)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();

        $unmatchedSongs = $sections->filter(
            fn (ServiceSection $s): bool => ($s->song_match_type === ServiceSectionSongMatchType::Unmatched || $s->song_match_type === null)
        );

        if ($unmatchedSongs->isEmpty()) {
            $this->logStepSkipped(ChurchServiceProcessingTimeline::MATCH_SONGS_FROM_TRANSCRIPT, 'No unmatched song sections to process');

            return;
        }

        $matchedCount = 0;
        $localSourcePath = null;
        $cleanupSourcePath = false;
        $ocrEnabled = (bool) config('media-processing.song_matching.ocr_enabled', true);
        $transcribeEnabled = (bool) config('media-processing.song_matching.transcribe_song_openings', true);

        if ($ocrEnabled || $transcribeEnabled) {
            try {
                [$localSourcePath, $cleanupSourcePath] = $this->resolveLocalSourceVideoPath($storageHelper);
            } catch (\Throwable $throwable) {
                Log::warning('MatchSongsFromTranscript: source video unavailable, skipping OCR and song opening transcription', [
                    'processing_id' => $this->processingLog->processing_id,
                    'error' => $throwable->getMessage(),
                ]);
                // Proceed without video-based strategies — title-hint matching still works.
                $ocrEnabled = false;
                $transcribeEnabled = false;
            }
        }

        try {
            foreach ($unmatchedSongs as $section) {
                if ($this->matchSectionFromTitleHint($section, $lyricsMatchingService)) {
                    $matchedCount++;

                    continue;
                }

                if ($ocrEnabled && $localSourcePath !== null) {
                    if ($this->matchSectionFromVideoOcr($section, $localSourcePath, $lyricsMatchingService, $ocrService)) {
                        $matchedCount++;

                        continue;
                    }
                }

                if ($transcribeEnabled && $localSourcePath !== null) {
                    if ($this->matchSectionFromOpeningTranscript(
                        $section,
                        $localSourcePath,
                        $lyricsMatchingService,
                        $videoExtractor,
                        $transcriptionService,
                        $localWhisperTranscriptionService
                    )) {
                        $matchedCount++;
                    }
                }
            }
        } finally {
            if ($cleanupSourcePath && $localSourcePath !== null) {
                $storageHelper->cleanupTempFile($localSourcePath);
            }
        }

        if ($matchedCount > 0) {
            $this->refreshReviewBookkeeping($sections, $unmatchedSongApplicator, $reviewSynchronizer, $identityResolver);
        }

        $this->logStepComplete(
            ChurchServiceProcessingTimeline::MATCH_SONGS_FROM_TRANSCRIPT,
            sprintf('Matched %d of %d unmatched song section(s)', $matchedCount, $unmatchedSongs->count())
        );

        Log::info('MatchSongsFromTranscript completed', [
            'processing_id' => $this->processingLog->processing_id,
            'matched' => $matchedCount,
            'unmatched_total' => $unmatchedSongs->count(),
        ]);
    }

    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed(
            ChurchServiceProcessingTimeline::MATCH_SONGS_FROM_TRANSCRIPT,
            $exception->getMessage()
        );
    }

    /**
     * Try to match a section using the song_title_hint written by SongTitleHintExtractor.
     * Returns true if a match was found and persisted.
     */
    private function matchSectionFromTitleHint(
        ServiceSection $section,
        SongLyricsMatchingService $lyricsMatchingService
    ): bool {
        $hint = $section->metadata['song_title_hint'] ?? null;
        if (! is_string($hint) || trim($hint) === '') {
            return false;
        }

        // Canonical key lookup first.
        $canonicalKey = Song::canonicalizeKey($hint);
        $song = Song::query()->where('canonical_key', $canonicalKey)->first();

        if ($song instanceof Song) {
            $this->applyMatch($section, $song->id, $song->title, 1.0, 'title_hint_canonical');

            return true;
        }

        // Fallback: run the hint text through lyrics matching as a transcript.
        $result = $lyricsMatchingService->matchFromLyrics($hint);
        if ($result['song_id'] !== null) {
            $this->applyMatch($section, $result['song_id'], (string) $result['matched_title'], $result['confidence'], 'title_hint_fuzzy');

            return true;
        }

        return false;
    }

    /**
     * Extract a video frame near the start of the song section, OCR the projected lyrics,
     * and attempt lyrics matching. Returns true if a match was found and persisted.
     */
    private function matchSectionFromVideoOcr(
        ServiceSection $section,
        string $localSourcePath,
        SongLyricsMatchingService $lyricsMatchingService,
        SongLyricOcrService $ocrService
    ): bool {
        try {
            $ocrText = $ocrService->extractLyrics(
                (float) $section->start_time,
                (float) $section->end_time,
                $localSourcePath
            );

            if ($ocrText === null || trim($ocrText) === '') {
                return false;
            }

            // Store OCR text in metadata for traceability.
            $metadataArray = $section->metadata?->toArray() ?? [];
            $metadataArray['song_ocr_text'] = $ocrText;
            $section->metadata = ServiceSectionMetadata::fromArray($metadataArray);
            $section->saveQuietly();

            $result = $lyricsMatchingService->matchFromLyrics($ocrText);
            if ($result['song_id'] !== null) {
                $this->applyMatch($section, $result['song_id'], (string) $result['matched_title'], $result['confidence'], 'ocr');

                return true;
            }
        } catch (\Throwable $throwable) {
            Log::warning('MatchSongsFromTranscript: OCR song matching failed', [
                'processing_id' => $this->processingLog->processing_id,
                'service_section_id' => $section->id,
                'error' => $throwable->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Transcribe the first N seconds of the song section and attempt lyrics matching.
     * Returns true if a match was found and persisted.
     */
    private function matchSectionFromOpeningTranscript(
        ServiceSection $section,
        string $localSourcePath,
        SongLyricsMatchingService $lyricsMatchingService,
        VideoExtractionService $videoExtractor,
        TranscriptionServiceInterface $transcriptionService,
        ?LocalWhisperTranscriptionService $localWhisperTranscriptionService
    ): bool {
        $openingSeconds = (int) config('media-processing.song_matching.song_opening_transcription_seconds', 30);
        $endTime = min((float) $section->start_time + $openingSeconds, (float) $section->end_time);

        $audioPath = null;

        try {
            $audioResult = $videoExtractor->extractOptimizedAudio(
                $localSourcePath,
                (object) [
                    'start_time' => (float) $section->start_time,
                    'end_time' => $endTime,
                ],
                $this->processingLog->processing_id.'_song_'.$section->id.'_opening.mp3'
            );

            $audioPath = $audioResult['audio_path'];
            $openingTranscript = $this->transcribeSongOpening(
                $transcriptionService,
                $localWhisperTranscriptionService,
                $audioPath,
                $this->processingLog->processing_id.'-song-'.$section->id.'-opening',
                $this->sermonDisk()
            );

            // Store the opening transcript in metadata for traceability.
            $metadataArray = $section->metadata?->toArray() ?? [];
            $metadataArray['song_opening_transcript'] = $openingTranscript;
            $section->metadata = ServiceSectionMetadata::fromArray($metadataArray);
            $section->saveQuietly();

            if (trim($openingTranscript) === '') {
                return false;
            }

            $result = $lyricsMatchingService->matchFromLyrics($openingTranscript);
            if ($result['song_id'] !== null) {
                $this->applyMatch($section, $result['song_id'], (string) $result['matched_title'], $result['confidence'], 'lyrics');

                return true;
            }
        } catch (\Throwable $throwable) {
            Log::warning('MatchSongsFromTranscript: failed to transcribe song opening', [
                'processing_id' => $this->processingLog->processing_id,
                'service_section_id' => $section->id,
                'error' => $throwable->getMessage(),
            ]);
        } finally {
            $this->cleanupExtractedAudio($audioPath);
        }

        return false;
    }

    private function transcribeSongOpening(
        TranscriptionServiceInterface $transcriptionService,
        ?LocalWhisperTranscriptionService $localWhisperTranscriptionService,
        string $audioPath,
        string $processingId,
        string $disk
    ): string {
        if (
            (bool) config('media-processing.song_matching.use_local_whisper_for_song_openings', false)
            && config('app.env') === 'local'
            && config('media-processing.transcription.service') === 'local'
            && $localWhisperTranscriptionService instanceof LocalWhisperTranscriptionService
        ) {
            return $localWhisperTranscriptionService->transcribeWithConfiguration(
                $audioPath,
                $processingId,
                $disk,
                [
                    'url' => (string) config('media-processing.song_matching.song_opening_local_whisper_url', 'http://whisper:8000'),
                    'transcription_path' => (string) config('media-processing.song_matching.song_opening_local_whisper_transcription_path', '/v1/audio/transcriptions'),
                    'model' => (string) config('media-processing.song_matching.song_opening_local_whisper_model', config('media-processing.transcription.local_whisper_model', 'small')),
                    'timeout' => max(1, (int) config('media-processing.song_matching.song_opening_local_whisper_timeout', config('media-processing.transcription.local_whisper_timeout', 1800))),
                ],
            );
        }

        return $transcriptionService->transcribe($audioPath, $processingId, $disk);
    }

    /**
     * Persist a song match onto the section and its linked ChurchServiceItem.
     */
    private function applyMatch(
        ServiceSection $section,
        int $songId,
        string $matchedTitle,
        float $confidence,
        string $matchSource
    ): void {
        DB::transaction(function () use ($section, $songId, $matchedTitle, $confidence, $matchSource): void {
            $metadataArray = $section->metadata?->toArray() ?? [];
            $metadataArray['transcript_song_match'] = [
                'song_id' => $songId,
                'title' => $matchedTitle,
                'confidence' => $confidence,
                'match_source' => $matchSource,
            ];

            // Clear the unmatched review flag now that we have a match.
            $reviewFlags = array_values(array_filter(
                $metadataArray['review_flags'] ?? [],
                fn (mixed $flag): bool => $flag !== 'unmatched_song_section'
            ));
            $metadataArray['review_flags'] = $reviewFlags;

            if (($metadataArray['review_reason'] ?? null) === 'unmatched_song_section') {
                unset($metadataArray['review_reason']);
            }

            $section->song_match_type = ServiceSectionSongMatchType::Inferred;
            $section->needs_manual_review = $reviewFlags !== [];
            $section->metadata = ServiceSectionMetadata::fromArray($metadataArray);
            $section->save();

            // Update the linked ChurchServiceItem if one exists.
            if ($section->church_service_item_id !== null) {
                $item = ChurchServiceItem::query()->find($section->church_service_item_id);
                if ($item instanceof ChurchServiceItem) {
                    $item->forceFill([
                        'song_id' => $songId,
                        'title' => $matchedTitle,
                    ])->save();
                }
            }
        });
    }

    /**
     * Re-run UnmatchedSongReviewApplicator and ChurchServiceReviewSynchronizer so that
     * the service-level review state reflects the new matches.
     *
     * @param  EloquentCollection<int, ServiceSection>  $allSongSections
     */
    private function refreshReviewBookkeeping(
        EloquentCollection $allSongSections,
        UnmatchedSongReviewApplicator $unmatchedSongApplicator,
        ChurchServiceReviewSynchronizer $reviewSynchronizer,
        MediaProcessingIdentityResolver $identityResolver
    ): void {
        $churchService = $this->resolveChurchService($identityResolver);
        if (! $churchService instanceof ChurchService) {
            return;
        }

        /** @var EloquentCollection<int, ServiceSection> $freshSections */
        $freshSections = ServiceSection::query()
            ->where('media_processing_log_id', $this->processingLog->id)
            ->orderBy('section_order')
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($freshSections, $unmatchedSongApplicator, $reviewSynchronizer, $churchService): void {
            // Collect IDs of all song sections that now have a confirmed or inferred match.
            $matchedIds = $freshSections
                ->filter(fn (ServiceSection $s): bool => $s->section_type === ServiceSectionType::Song
                    && in_array($s->song_match_type, [ServiceSectionSongMatchType::Confirmed, ServiceSectionSongMatchType::Inferred], true)
                )
                ->pluck('id')
                ->values()
                ->all();

            $unmatchedSections = $unmatchedSongApplicator->apply($freshSections, $matchedIds);

            foreach ($freshSections as $section) {
                $section->save();
            }

            // Derive review triggers from the now-refreshed unmatched count.
            $reviewTriggers = $unmatchedSections->isNotEmpty()
                ? ['unmatched_song_sections']
                : [];

            $reviewSynchronizer->sync($churchService, $freshSections, $reviewTriggers);
        });
    }

    private function resolveChurchService(MediaProcessingIdentityResolver $identityResolver): ?ChurchService
    {
        if ($this->processingLog->church_service_id !== null) {
            return ChurchService::query()->find($this->processingLog->church_service_id);
        }

        $identity = $identityResolver->resolve($this->processingLog);
        if ($identity === null) {
            return null;
        }

        return ChurchService::query()
            ->where('date', $identity['date'])
            ->where('service', $identity['service']->value)
            ->first();
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveLocalSourceVideoPath(StorageAdapterHelper $storageHelper): array
    {
        $sourceFilePath = $this->requireSourceFilePath();
        $tempDisk = (string) config('media-processing.storage.temp_disk', 'local');

        if ($this->isS3Disk($tempDisk)) {
            if (! Storage::disk($tempDisk)->exists($sourceFilePath)) {
                throw new \RuntimeException('Source video not found on temp disk');
            }

            return [
                $storageHelper->downloadToTemp($sourceFilePath, $tempDisk, 'local', 'temp/song-matching'),
                true,
            ];
        }

        $localSourcePath = Storage::disk($tempDisk)->path($sourceFilePath);
        if (! file_exists($localSourcePath)) {
            throw new \RuntimeException('Source video file not found');
        }

        return [$localSourcePath, false];
    }

    private function requireSourceFilePath(): string
    {
        $sourceFilePath = $this->processingLog->source_file_path;
        if (! is_string($sourceFilePath) || $sourceFilePath === '') {
            throw new \RuntimeException('No source video path found in processing log');
        }

        return $sourceFilePath;
    }

    private function cleanupExtractedAudio(?string $audioPath): void
    {
        if (! is_string($audioPath) || $audioPath === '') {
            return;
        }

        $disk = $this->sermonDisk();

        try {
            if (Storage::disk($disk)->exists($audioPath)) {
                Storage::disk($disk)->delete($audioPath);
            }
        } catch (\Throwable $throwable) {
            Log::warning('MatchSongsFromTranscript: failed to clean up extracted audio', [
                'processing_id' => $this->processingLog->processing_id,
                'audio_path' => $audioPath,
                'disk' => $disk,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function sermonDisk(): string
    {
        return (string) config('media-processing.storage.sermon_disk', 'public');
    }
}
