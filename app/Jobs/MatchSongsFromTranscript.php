<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\TranscriptionServiceInterface;
use App\Data\ServiceSectionMetadata;
use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\Media\Audio\LocalWhisperTranscriptionService;
use App\Services\Media\Video\VideoExtractionService;
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
        VideoExtractionService $videoExtractor,
        StorageAdapterHelper $storageHelper,
        TranscriptionServiceInterface $transcriptionService,
        SongLyricOcrService $ocrService,
        UnmatchedSongReviewApplicator $unmatchedSongReviewApplicator,
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
            fn (ServiceSection $s): bool => $this->needsSongMatching($s)
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
                Log::warning('MatchSongsFromTranscript: source video unavailable, skipping frame extraction and song opening transcription', [
                    'processing_id' => $this->processingLog->processing_id,
                    'error' => $throwable->getMessage(),
                ]);
                // Proceed without the video — title-hint matching and OCR matching
                // against previously stored frame text still work.
                $transcribeEnabled = false;
            }
        }

        try {
            foreach ($unmatchedSongs as $section) {
                if ($this->matchSectionFromTitleHint($section, $lyricsMatchingService)) {
                    $matchedCount++;

                    continue;
                }

                if ($ocrEnabled) {
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

        $matchedSectionIds = $sections
            ->reject(fn (ServiceSection $section): bool => $this->needsSongMatching($section))
            ->pluck('id')
            ->all();

        foreach ($unmatchedSongReviewApplicator->apply($sections, $matchedSectionIds) as $section) {
            $section->save();
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
     * A section needs song matching when it has no match at all, or when OoS alignment
     * could only infer a positional label and the unmatched review flag is still present
     * (i.e. there is no catalog-backed evidence for the song yet).
     */
    private function needsSongMatching(ServiceSection $section): bool
    {
        if ($section->song_match_type === ServiceSectionSongMatchType::Unmatched || $section->song_match_type === null) {
            return true;
        }

        if ($section->song_match_type !== ServiceSectionSongMatchType::Inferred) {
            return false;
        }

        $reviewFlags = $section->metadata['review_flags'] ?? [];

        return is_array($reviewFlags) && in_array('unmatched_song_section', $reviewFlags, true);
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

        // A heard "title" is often the first lyric line, not the catalogued
        // title. first_line_key is not unique, and an ambiguous hit must not
        // persist an arbitrary song — leave it to fuzzy lyrics matching, which
        // can weigh the rest of the hint.
        $firstLineMatches = Song::query()
            ->whereIn('first_line_key', Song::matchKeyVariants($hint))
            ->limit(2)
            ->get();

        if ($firstLineMatches->count() === 1) {
            $song = $firstLineMatches->sole();
            $this->applyMatch($section, $song->id, $song->title, 0.95, 'title_hint_first_line');

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
     * Separator used when storing multiple OCR frame texts in song_ocr_text metadata.
     */
    private const string OCR_SAMPLE_SEPARATOR = "\n---\n";

    /**
     * OCR projected lyrics from frames sampled across the song section and attempt
     * lyrics matching. Several frames are sampled so back-to-back songs merged into
     * one section can both be identified: the first match becomes the section's
     * primary match and further distinct matches are stored as alignment evidence.
     *
     * Previously stored OCR text is reused so re-runs work without the source video.
     * Returns true if a primary match was found and persisted.
     */
    private function matchSectionFromVideoOcr(
        ServiceSection $section,
        ?string $localSourcePath,
        SongLyricsMatchingService $lyricsMatchingService,
        SongLyricOcrService $ocrService
    ): bool {
        try {
            $ocrTexts = $this->resolveOcrTexts($section, $localSourcePath, $ocrService);

            if ($ocrTexts === []) {
                return false;
            }

            /** @var array<int, array{song_id: int, confidence: float, matched_title: string|null}> $matches */
            $matches = [];

            foreach ($ocrTexts as $ocrText) {
                // OCR frames are sampled across the section, so the first
                // visible line is not necessarily the song opening — skip the
                // first-line-key shortcut and let fuzzy matching weigh the text.
                $result = $lyricsMatchingService->matchFromLyrics($ocrText, allowFirstLineKeyMatch: false);
                $songId = $result['song_id'];

                if ($songId !== null && ! array_key_exists($songId, $matches)) {
                    $matches[$songId] = [
                        'song_id' => $songId,
                        'confidence' => $result['confidence'],
                        'matched_title' => $result['matched_title'],
                    ];
                }
            }

            if ($matches === []) {
                return false;
            }

            $primary = array_shift($matches);

            if ($matches !== []) {
                $metadataArray = $section->metadata?->toArray() ?? [];
                $metadataArray['additional_song_matches'] = array_values(array_map(
                    static fn (array $match): array => [
                        'song_id' => $match['song_id'],
                        'title' => $match['matched_title'],
                        'confidence' => $match['confidence'],
                        'match_source' => 'ocr',
                    ],
                    $matches
                ));
                $section->metadata = ServiceSectionMetadata::fromArray($metadataArray);
                $section->saveQuietly();
            }

            $this->applyMatch($section, $primary['song_id'], (string) $primary['matched_title'], $primary['confidence'], 'ocr');

            return true;
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
     * Return the OCR texts for a section: previously stored text when available,
     * otherwise freshly sampled frames (persisted for traceability and re-runs).
     *
     * @return array<int, string>
     */
    private function resolveOcrTexts(ServiceSection $section, ?string $localSourcePath, SongLyricOcrService $ocrService): array
    {
        $storedText = $section->metadata['song_ocr_text'] ?? null;

        if (is_string($storedText) && trim($storedText) !== '') {
            return array_values(array_filter(array_map(
                'trim',
                explode(self::OCR_SAMPLE_SEPARATOR, $storedText)
            ), static fn (string $text): bool => $text !== ''));
        }

        if ($localSourcePath === null) {
            return [];
        }

        $ocrTexts = $ocrService->extractLyricsSamples(
            (float) $section->start_time,
            (float) $section->end_time,
            $localSourcePath
        );

        if ($ocrTexts !== []) {
            $metadataArray = $section->metadata?->toArray() ?? [];
            $metadataArray['song_ocr_text'] = implode(self::OCR_SAMPLE_SEPARATOR, $ocrTexts);
            $section->metadata = ServiceSectionMetadata::fromArray($metadataArray);
            $section->saveQuietly();
        }

        return $ocrTexts;
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

            // A confident match displays the catalogued title rather than the
            // heard text ("What love could remember" → "His Mercy Is More").
            // song_title_hint keeps the heard text as evidence; a shaky fuzzy
            // match must not present a confidently wrong title.
            $writebackThreshold = (float) config('media-processing.song_matching.title_writeback_min_confidence', 0.75);
            $writeCatalogueTitle = $confidence >= $writebackThreshold;

            if ($writeCatalogueTitle) {
                $displayTitle = $this->catalogueDisplayTitle($matchedTitle);
                $metadataArray['song_title'] = $displayTitle;
                $section->title = $displayTitle;
            }

            // Clear the unmatched review flag now that we have a match.
            $reviewFlags = array_values(array_filter(
                $metadataArray['review_flags'] ?? [],
                fn (mixed $flag): bool => $flag !== 'unmatched_song_section'
            ));
            $metadataArray['review_flags'] = $reviewFlags;

            if (($metadataArray['review_reason'] ?? null) === 'unmatched_song_section') {
                unset($metadataArray['review_reason']);
            }

            $section->song_match_type = $writeCatalogueTitle
                ? ServiceSectionSongMatchType::Confirmed
                : ServiceSectionSongMatchType::Inferred;
            $section->needs_manual_review = $reviewFlags !== [];
            $section->metadata = ServiceSectionMetadata::fromArray($metadataArray);
            $section->save();

            // Commit the catalogue song to the linked ChurchServiceItem, but
            // only when confident: the review timeline derives a song section's
            // displayed title from item->song, so a sub-threshold write would
            // resurface the very catalogue title the section gate withheld.
            if ($writeCatalogueTitle && $section->church_service_item_id !== null) {
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
     * The catalogue title with any trailing hymn-book number removed
     * ("Jesus Shall Take The Highest Honour #305", "Go Forth And Tell #616")
     * — OpenLP catalogue bookkeeping, not part of the song's title. The raw
     * title is preserved in transcript_song_match and on the catalogue row.
     */
    private function catalogueDisplayTitle(string $title): string
    {
        $stripped = trim((string) preg_replace('/\s*#\w+$/', '', trim($title)));

        return $stripped === '' ? $title : $stripped;
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
