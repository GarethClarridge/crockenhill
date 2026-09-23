<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSectionMetadata;
use App\Data\SuspectTranscriptBlock;
use App\Enums\ChurchServiceItemSource;
use App\Enums\MediaType;
use App\Enums\ProcessingStep;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Song\SongLyricIdentityCheck;
use App\Services\Song\SongLyricOcrService;
use App\Services\Song\SongLyricsMatchingService;
use App\Services\Song\UnmatchedSongReviewApplicator;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\SectionReviewFlagPolicy;
use App\Support\SongCatalogueTitlePolicy;
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

    private ?SongLyricIdentityCheck $lyricIdentityCheck = null;

    /** @var array{0: ChurchServiceTranscript|null}|null Read once per run, and only if a match needs it. */
    private ?array $serviceTranscript = null;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {
        $this->onQueue((string) config('media-processing.queues.audio', 'audio-processing'));
    }

    /**
     * Seconds to wait before each retry.
     *
     * This stage bills a provider. Without a backoff the queue retries at once,
     * so a rate limit or a 5xx burns all three attempts in seconds — and pays
     * for each one that reached the model before failing. The delays match
     * {@see ProcessTranscriptWithAI::backoff()}, the pipeline's other
     * paid stage.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [120, 300, 600];
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
        StorageAdapterHelper $storageHelper,
        SongLyricOcrService $ocrService,
        UnmatchedSongReviewApplicator $unmatchedSongReviewApplicator,
        ?SongLyricIdentityCheck $lyricIdentityCheck = null,
    ): void {
        $this->lyricIdentityCheck = $lyricIdentityCheck ?? app(SongLyricIdentityCheck::class);
        $this->serviceTranscript = null;

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

        if ($ocrEnabled) {
            try {
                [$localSourcePath, $cleanupSourcePath] = $this->resolveLocalSourceVideoPath($storageHelper);
            } catch (\Throwable $throwable) {
                Log::warning('MatchSongsFromTranscript: source video unavailable, skipping frame extraction', [
                    'processing_id' => $this->processingLog->processing_id,
                    'error' => $throwable->getMessage(),
                ]);
                // Previously stored OCR text remains available for re-runs.
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
     * (i.e. there is no catalog-backed evidence for the song yet). A title inferred
     * from suspect transcript text, or contradicted by the section's sung lyrics,
     * also remains eligible for independent OCR.
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

        return is_array($reviewFlags) && (
            in_array('unmatched_song_section', $reviewFlags, true)
            || in_array(SongCatalogueTitlePolicy::FLAG_IDENTITY_UNVERIFIED_FROM_SUSPECT_TRANSCRIPT, $reviewFlags, true)
            || in_array(SongCatalogueTitlePolicy::FLAG_IDENTITY_CONTRADICTED_BY_LYRICS, $reviewFlags, true)
            || in_array(SongCatalogueTitlePolicy::FLAG_IDENTITY_SINGLE_SOURCE, $reviewFlags, true)
        );
    }

    /**
     * Try to match a section using the song title detected from the full-service transcript.
     * Returns true if the persisted match needs no further corroboration.
     */
    private function matchSectionFromTitleHint(
        ServiceSection $section,
        SongLyricsMatchingService $lyricsMatchingService
    ): bool {
        $hint = $section->metadata['song_title_hint'] ?? null;
        if (! is_string($hint) || trim($hint) === '') {
            return false;
        }

        $result = $lyricsMatchingService->matchTitleHint($hint);
        if ($result['song_id'] !== null) {
            $this->applyMatch($section, $result['song_id'], (string) $result['matched_title'], $result['confidence'], (string) $result['match_source']);

            return ! $this->needsSongMatching($section);
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
     * Persist a song match onto the section and its linked ChurchServiceItem.
     */
    /**
     * Which fields an automated match may write back to a canonical item.
     *
     * Only items the run itself authored. Writing to an order-of-service item
     * here would put the merge decision in two places: ChurchServiceItemSyncService
     * already fills a blank song_id from the confirmed section on the next
     * projection, with the full authority model applied. Worse, writing an
     * audio-derived song_id onto a planned item would then let the next
     * projection read that same id back as independent corroboration and anchor
     * on it — a wrong match quietly vouching for itself.
     *
     * @return array<string, mixed>
     */
    private function itemMatchWriteback(ChurchServiceItem $item, int $songId, string $matchedTitle): array
    {
        if ($item->source !== ChurchServiceItemSource::Livestream) {
            return [];
        }

        return ['song_id' => $songId, 'title' => $matchedTitle];
    }

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

            $reviewFlags = array_values(array_filter(
                $metadataArray['review_flags'] ?? [],
                static fn (mixed $flag): bool => is_string($flag)
                    && $flag !== 'unmatched_song_section'
                    && $flag !== SongCatalogueTitlePolicy::FLAG_IDENTITY_UNVERIFIED_FROM_SUSPECT_TRANSCRIPT
                    && $flag !== SongCatalogueTitlePolicy::FLAG_IDENTITY_CONTRADICTED_BY_LYRICS
                    && $flag !== SongCatalogueTitlePolicy::FLAG_IDENTITY_SINGLE_SOURCE,
            ));

            if ($matchSource !== 'ocr' && $this->overlapsSuspectTranscriptBlock($section)) {
                $reviewFlags[] = SongCatalogueTitlePolicy::FLAG_IDENTITY_UNVERIFIED_FROM_SUSPECT_TRANSCRIPT;
            }

            // OCR reads the projected slides, which is the independent evidence
            // this check defers to, so only a match taken from what was heard is
            // put against what was sung.
            $check = null;

            if ($matchSource !== 'ocr') {
                $check = $this->lyricIdentityCheck($section, $songId);
                $metadataArray['lyric_identity_check'] = $check;

                if ($check['verdict'] === SongLyricIdentityCheck::CONTRADICTED) {
                    $reviewFlags[] = SongCatalogueTitlePolicy::FLAG_IDENTITY_CONTRADICTED_BY_LYRICS;
                }
            }

            $sources = $this->independentSources($section, $songId, $matchSource, $check);
            $metadataArray['identity_sources'] = $sources;

            if (count($sources) < 2) {
                $reviewFlags[] = SongCatalogueTitlePolicy::FLAG_IDENTITY_SINGLE_SOURCE;
            }

            // A confident match displays the catalogued title rather than the
            // heard text ("What love could remember" → "His Mercy Is More").
            // song_title_hint keeps the heard text as evidence; a shaky fuzzy
            // match must not present a confidently wrong title. The threshold
            // and the evidence vetoes both live in the shared policy so the
            // re-derivation path cannot answer this differently.
            $markerMismatch = in_array(
                ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH,
                $reviewFlags,
                true,
            );

            $writeCatalogueTitle = SongCatalogueTitlePolicy::writesCatalogueTitle(
                $confidence,
                $reviewFlags,
            );

            if ($writeCatalogueTitle) {
                $displayTitle = $this->catalogueDisplayTitle($matchedTitle);
                $metadataArray['song_title'] = $displayTitle;
                $section->title = $displayTitle;
            }

            // Keep the mismatch flag: a match does not settle which naming was right.
            if ($markerMismatch && ! in_array(ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH, $reviewFlags, true)) {
                $reviewFlags[] = ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH;
            }
            $metadataArray['review_flags'] = $reviewFlags;

            if (($metadataArray['review_reason'] ?? null) === 'unmatched_song_section') {
                unset($metadataArray['review_reason']);
            }

            $section->song_match_type = $writeCatalogueTitle
                ? ServiceSectionSongMatchType::Confirmed
                : ServiceSectionSongMatchType::Inferred;
            $section->needs_manual_review = SectionReviewFlagPolicy::requiresManualReview(
                $section->section_type,
                $reviewFlags,
                is_string($metadataArray['sermon_reference'] ?? null)
                    ? $metadataArray['sermon_reference']
                    : null,
            );
            $section->metadata = ServiceSectionMetadata::fromArray($metadataArray);
            $section->save();

            // Commit the catalogue song to the linked ChurchServiceItem, but
            // only when confident: the review timeline derives a song section's
            // displayed title from item->song, so a sub-threshold write would
            // resurface the very catalogue title the section gate withheld.
            if ($writeCatalogueTitle && $section->church_service_item_id !== null) {
                $item = ChurchServiceItem::query()->find($section->church_service_item_id);
                if ($item instanceof ChurchServiceItem) {
                    $item->forceFill($this->itemMatchWriteback($item, $songId, $matchedTitle))->save();
                }
            }
        });
    }

    /**
     * The independent sources that agree on this song, by lineage.
     *
     * `heard` is the leader's announcement (a title-hint match, or a hint that resolves to the
     * same song), `sung` the section's own words sharing two or more lyric pairs without a
     * contradiction, `projected` the slides read by OCR, and `planned` an order-of-service item
     * the run did not author. A livestream item is written from this very match, so it never
     * counts: it would let the match vouch for itself.
     *
     * @param  array<string, mixed>|null  $lyricCheck
     * @return list<'heard'|'sung'|'projected'|'planned'>
     */
    private function independentSources(ServiceSection $section, int $songId, string $matchSource, ?array $lyricCheck): array
    {
        $sources = [];
        $hint = $section->metadata['song_title_hint'] ?? null;

        if (str_starts_with($matchSource, 'title_hint')
            || (is_string($hint) && trim($hint) !== '' && app(SongLyricsMatchingService::class)->matchTitleHint($hint)['song_id'] === $songId)) {
            $sources[] = 'heard';
        }

        $lyricCheck ??= $this->lyricIdentityCheck($section, $songId);

        if (($lyricCheck['bound_score']['word_pairs'] ?? 0) >= 2 && $lyricCheck['verdict'] !== SongLyricIdentityCheck::CONTRADICTED) {
            $sources[] = 'sung';
        }

        if ($matchSource === 'ocr') {
            $sources[] = 'projected';
        }

        $serviceId = $this->processingLog->church_service_id;

        if ($serviceId !== null && ChurchServiceItem::query()
            ->where('church_service_id', $serviceId)
            ->where('source', '!=', ChurchServiceItemSource::Livestream->value)
            ->where('song_id', $songId)
            ->exists()) {
            $sources[] = 'planned';
        }

        return $sources;
    }

    /**
     * The section's sung words put against the song it is about to be bound to.
     *
     * A run whose transcript cannot be read is recorded as `unavailable` and
     * left to the other gates: the check vetoes on evidence, never on its absence.
     *
     * @return array<string, mixed>
     */
    private function lyricIdentityCheck(ServiceSection $section, int $songId): array
    {
        $this->serviceTranscript ??= [app(ServiceTranscriptReader::class)->tryRead($this->processingLog)];
        $transcript = $this->serviceTranscript[0];

        if ($transcript === null) {
            return ['verdict' => 'unavailable', 'bound_song_id' => $songId];
        }

        $check = $this->lyricIdentityCheck ?? app(SongLyricIdentityCheck::class);

        return $check->assess($transcript, (float) $section->start_time, (float) $section->end_time, $songId);
    }

    private function overlapsSuspectTranscriptBlock(ServiceSection $section): bool
    {
        foreach ($section->processingLog->recordedTranscriptSuspectBlocks() ?? [] as $recorded) {
            if (SuspectTranscriptBlock::fromArray($recorded)->overlaps(
                (float) $section->start_time,
                (float) $section->end_time,
            )) {
                return true;
            }
        }

        return false;
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
}
