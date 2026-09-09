<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\RedetectStructureOnRecoveredEvidence;
use App\Data\HistoricStagingContext;
use App\Data\ProcessingManualReviewMetadata;
use App\Data\ProcessingMetadata;
use App\Data\ChurchServiceTranscript;
use App\Data\ProcessingMetadataCast;
use App\Data\SermonAnalysis;
use App\Data\SermonAnalysisCast;
use App\Data\ServiceSermonAbsence;
use App\Data\ServiceStructure;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\ProcessTranscriptWithAI;
use App\Jobs\StoreSermonVideo;
use App\Services\HistoricMedia\HistoricReviewSourceReclaimer;
use App\Services\HistoricMedia\HistoricStagingGuard;
use App\Services\Media\Audio\ServiceTranscriptRepetitionScreen;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Services\Processing\SermonMetadataIntegrationService;
use App\Support\ServiceArtifactDisk;
use Database\Factories\MediaProcessingLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $processing_id
 * @property int|null $historic_import_operation_id
 * @property MediaType $processing_type
 * @property ProcessingStatus $status
 * @property string|null $current_step
 * @property string|null $error_message
 * @property string $original_filename
 * @property string|null $file_hash
 * @property string|null $dedup_key
 * @property int|null $file_size
 * @property float|null $duration
 * @property Carbon|null $extracted_date
 * @property SermonService|null $extracted_service
 * @property string|null $source_file_path
 * @property string|null $stored_file_path
 * @property string|null $audio_file_path
 * @property string|null $enhanced_audio_file_path
 * @property string|null $video_file_path
 * @property string|null $transcript_file_path
 * @property string|null $rms_log_path
 * @property float|null $sermon_start_time
 * @property float|null $sermon_end_time
 * @property SermonAnalysis|null $ai_analysis
 * @property ProcessingMetadata|null $processing_metadata
 * @property string|null $threshold_method
 * @property float|null $adaptive_threshold
 * @property array<string, mixed>|null $rms_stats
 * @property array<int, array<string, mixed>>|null $visual_samples
 * @property float|null $visual_processing_time
 * @property int|null $sermon_id
 * @property int|null $owner_user_id
 * @property int|null $church_service_id
 * @property string|null $queue_name
 * @property string|null $job_id
 * @property int|null $attempt_count
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property bool $is_degraded_completion
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChurchService|null $churchService
 * @property-read User|null $owner
 * @property-read Collection<int, SermonProcessingStep> $processingSteps
 * @property-read Sermon|null $sermon
 * @property-read Collection<int, LivestreamSegment> $segments
 * @property-read Collection<int, ServiceSection> $serviceSections
 *
 * @phpstan-import-type SuspectTranscriptBlockShape from \App\Data\SuspectTranscriptBlock
 */
class MediaProcessingLog extends Model
{
    /** @use HasFactory<MediaProcessingLogFactory> */
    use HasFactory;

    public const VIDEO_PROCESSING_MODE_FULL_VIDEO = 'full_video';

    public const VIDEO_PROCESSING_MODE_AUTO_TRIM = 'auto_trim';

    /**
     * The run's source recording has no usable audio at all — every RMS
     * sample in its log is digital silence. See {@see isExcludedSilentAudio()}.
     */
    public const EXCLUSION_REASON_SOURCE_AUDIO_SILENT = 'source_audio_silent';

    /**
     * The source recording holds no sermon at all — it captured a different part
     * of the service, or too little of it. Unlike a silent source this cannot be
     * detected from the audio, so it is only ever recorded by an operator who has
     * looked at the recording.
     */
    public const EXCLUSION_REASON_NO_SERMON_IN_SOURCE = 'no_sermon_in_source';

    /** Every reason a run may be excluded under. */
    public const EXCLUSION_REASONS = [
        self::EXCLUSION_REASON_SOURCE_AUDIO_SILENT,
        self::EXCLUSION_REASON_NO_SERMON_IN_SOURCE,
    ];

    protected $fillable = [
        'processing_id',
        'historic_import_operation_id',
        'processing_type',
        'status',
        'current_step',
        'error_message',

        // File info
        'original_filename',
        'file_hash',
        'dedup_key',
        'file_size',
        'duration',
        'extracted_date',
        'extracted_service',

        // File paths
        'source_file_path',
        'stored_file_path', // Alias for source_file_path (backward compatibility)
        'audio_file_path',
        'enhanced_audio_file_path',
        'video_file_path',
        'transcript_file_path',

        // Livestream-specific
        'rms_log_path',
        'sermon_start_time',
        'sermon_end_time',

        // Processing results
        'ai_analysis',
        'processing_metadata',

        // Adaptive threshold fields (for livestream processing)
        'threshold_method',
        'adaptive_threshold',
        'rms_stats',

        // Relationships
        'sermon_id',
        'owner_user_id',
        'church_service_id',

        // Queue correlation
        'queue_name',
        'job_id',
        'attempt_count',

        // Timestamps
        'started_at',
        'completed_at',
        'is_degraded_completion',

        // Supersession (a later/better run for the same service won)
        'superseded_at',
        'superseded_by_processing_log_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'processing_type' => MediaType::class,
            'status' => ProcessingStatus::class,
            'ai_analysis' => SermonAnalysisCast::class,
            'processing_metadata' => ProcessingMetadataCast::class,
            'rms_stats' => 'array',
            'duration' => 'float',
            'extracted_date' => 'date',
            'extracted_service' => SermonService::class,
            'sermon_start_time' => 'float',
            'sermon_end_time' => 'float',
            'adaptive_threshold' => 'float',
            'file_size' => 'integer',
            'sermon_id' => 'integer',
            'owner_user_id' => 'integer',
            'church_service_id' => 'integer',
            'attempt_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'is_degraded_completion' => 'boolean',
        ];
    }

    // Relationships

    /**
     * @return BelongsTo<Sermon, $this>
     */
    public function sermon(): BelongsTo
    {
        return $this->belongsTo(Sermon::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return BelongsTo<ChurchService, $this>
     */
    public function churchService(): BelongsTo
    {
        return $this->belongsTo(ChurchService::class);
    }

    /** @return BelongsTo<HistoricImportOperation, $this> */
    public function historicImportOperation(): BelongsTo
    {
        return $this->belongsTo(HistoricImportOperation::class);
    }

    /**
     * @return HasMany<LivestreamSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(LivestreamSegment::class, 'media_processing_log_id');
    }

    /**
     * @return HasMany<ServiceSection, $this>
     */
    public function serviceSections(): HasMany
    {
        return $this->hasMany(ServiceSection::class, 'media_processing_log_id');
    }

    /**
     * @return HasMany<SermonProcessingStep, $this>
     */
    public function processingSteps(): HasMany
    {
        return $this->hasMany(SermonProcessingStep::class, 'processing_id', 'processing_id');
    }

    // Scopes

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeByType(Builder $query, MediaType $type): Builder
    {
        return $query->where('processing_type', $type->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeAudio(Builder $query): Builder
    {
        return $query->where('processing_type', MediaType::Audio->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeVideo(Builder $query): Builder
    {
        return $query->where('processing_type', MediaType::Video->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeLivestream(Builder $query): Builder
    {
        return $query->where('processing_type', MediaType::Livestream->value);
    }

    /**
     * Runs that produce service sections: livestreams and auto-trim video
     * runs — the SQL mirror of usesSegmentationPipeline().
     *
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    /**
     * Runs left in place but no longer the authoritative processing of their
     * service — a later/better run for the same service won. Their sections are
     * kept for audit but excluded from every review and timeline surface.
     *
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeNotSuperseded(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeSuperseded(Builder $query): Builder
    {
        return $query->whereNotNull('superseded_at');
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeSegmentationPipeline(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('processing_type', MediaType::Livestream->value)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('processing_type', MediaType::Video->value)
                        ->where(function (Builder $query): void {
                            $query
                                ->where('processing_metadata->video_processing_mode', self::VIDEO_PROCESSING_MODE_AUTO_TRIM)
                                ->orWhere('processing_metadata->trim_requested', true);
                        });
                });
        });
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::Processing->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::Pending->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::Completed->value);
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', ProcessingStatus::Failed->value);
    }

    /**
     * Runs that still own an unresolved review or approval obligation.
     *
     * Keep this predicate aligned with {@see self::reviewSourceRetentionReasons()}:
     * these are the four current review signals, deliberately excluding any
     * future material-risk classification until it has a real routing flag, and
     * excluding retired runs entirely — a withdrawn result holds no obligation.
     *
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeWithUnresolvedReviewObligation(Builder $query): Builder
    {
        return $query->whereNull('superseded_at')->where(function (Builder $query): void {
            $query
                ->whereHas('serviceSections', function (Builder $query): void {
                    $query
                        ->where('publication_status', ServiceSectionPublicationStatus::PendingApproval->value)
                        ->orWhere('needs_manual_review', true);
                })
                ->orWhere('processing_metadata->video_quality->status', SermonVideoQualityStatus::NeedsReview->value)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('status', ProcessingStatus::Failed->value)
                        ->where('current_step', 'manual_review_required');
                })
                ->orWhere(function (Builder $query): void {
                    $query
                        ->whereNotNull('processing_metadata->service_structure->sermon_absence')
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('church_service_id')
                                ->orWhereHas(
                                    'churchService',
                                    fn (Builder $service): Builder => $service->whereNull('occasion_confirmed_at'),
                                );
                        });
                });
        });
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeAwaitingManualSermonReview(Builder $query): Builder
    {
        return $query
            ->whereNull('superseded_at')
            ->where('status', ProcessingStatus::Failed->value)
            ->where('current_step', 'manual_review_required')
            ->where(function (Builder $query): void {
                $query->whereNotNull('processing_metadata->manual_review->reason_code');

                foreach (self::legacyManualReviewReasonPatterns() as $pattern) {
                    $query->orWhere(function (Builder $query) use ($pattern): void {
                        // Legacy fallback rows only ever existed for livestream runs.
                        $query
                            ->where('processing_type', MediaType::Livestream->value)
                            ->where('error_message', 'like', '%'.$pattern.'%');
                    });
                }
            });
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * @param  Builder<MediaProcessingLog>  $query
     * @return Builder<MediaProcessingLog>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canAccessAdmin()) {
            return $query;
        }

        return $query->where('owner_user_id', $user->id);
    }

    // Status helpers

    public function isComplete(): bool
    {
        return $this->status === ProcessingStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === ProcessingStatus::Failed;
    }

    public function isProcessing(): bool
    {
        return $this->status === ProcessingStatus::Processing;
    }

    public function isPending(): bool
    {
        return $this->status === ProcessingStatus::Pending;
    }

    public function isCancelled(): bool
    {
        return $this->status === ProcessingStatus::Cancelled;
    }

    public function historicImportJobKey(): ?string
    {
        $jobKey = data_get($this->processing_metadata?->toArray(), 'historic_import.job_key');

        return is_string($jobKey) && $jobKey !== '' ? $jobKey : null;
    }

    /**
     * Whether this run is re-cutting a sermon that has already been published.
     *
     * Set by {@see ProcessingRunOrchestrator::reExtract()} and
     * cleared once the new video is stored. It is the one signal that permits replacing
     * an existing sermon video: the guard in
     * {@see SermonMetadataIntegrationService} otherwise refuses,
     * which is right for every accidental rewrite but wrong for a deliberate re-cut.
     */
    public function isReExtraction(): bool
    {
        return (bool) data_get($this->processing_metadata?->toArray(), 're_extraction.requested');
    }

    public function markAsReExtraction(): void
    {
        $this->writeProcessingMetadata(static function (array $metadata): array {
            $metadata['re_extraction'] = ['requested' => true, 'requested_at' => now()->toISOString()];

            return $metadata;
        });
    }

    /**
     * Record which extraction produced the sermon video now on disk.
     *
     * Written by {@see StoreSermonVideo} at the moment it stores one,
     * because "the store job completed" and "the stored video matches this run's
     * current cut" are different facts and the pipeline was reading the first as
     * though it were the second. A retry that resumes at extraction re-cuts the
     * sermon, rewrites the audio and the transcript, and then finds the store
     * step already marked complete -- so the sermon keeps a video from the
     * superseded cut while everything else describes the new one.
     *
     * 44 sermons reached that state: 24 hold a video shorter than their own
     * sermon (worst six minutes short) and 20 hold one that runs past it.
     */
    public function recordStoredSermonVideo(float $observedDuration): void
    {
        $this->writeProcessingMetadata(static function (array $metadata) use ($observedDuration): array {
            $metadata['stored_video'] = [
                'observed_duration' => $observedDuration,
                'stored_at' => now()->toISOString(),
            ];

            return $metadata;
        });
    }

    /**
     * The observed duration of the sermon video currently on disk, or null when
     * that cannot be established.
     *
     * Null is deliberately "cannot tell", never "nothing is stored": a caller
     * that cannot see what is on disk must leave the stored video alone rather
     * than assume it is stale and replace it.
     *
     * Runs that predate {@see self::recordStoredSermonVideo()} fall back to the
     * previous extraction's observed duration, which is what the store step put
     * on disk for any run whose store was not skipped -- but only where a video
     * was actually linked to a sermon, so a first extraction is not mistaken for
     * a replacement.
     */
    public function storedSermonVideoDuration(): ?float
    {
        $metadata = $this->processing_metadata?->toArray() ?? [];

        $recorded = data_get($metadata, 'stored_video.observed_duration');

        if (is_numeric($recorded) && (float) $recorded > 0.0) {
            return (float) $recorded;
        }

        if (! filled($this->sermon?->video_file_path)) {
            return null;
        }

        $previous = data_get($metadata, 'trim.observed_duration');

        return is_numeric($previous) && (float) $previous > 0.0 ? (float) $previous : null;
    }

    /**
     * Record the content now in the sermon transcript, so a later reader can
     * tell whether the banked analysis still describes it.
     *
     * Called by every writer that rewrites a sermon transcript. Analysis is
     * derived from that text, so rewriting the text stales the analysis even
     * though the analysis job itself completed successfully — the same
     * distinction {@see self::recordStoredSermonVideo()} draws for video, and
     * for the same reason: "the job completed" and "its output matches this
     * run's current input" are different facts.
     */
    public function recordTranscriptContent(string $contentHash): void
    {
        $this->writeProcessingMetadata(static function (array $metadata) use ($contentHash): array {
            $metadata['transcript_content'] = [
                'hash' => $contentHash,
                'changed_at' => now()->toIso8601String(),
            ];

            return $metadata;
        });
    }

    /**
     * Record the transcript that analysis actually consumed.
     *
     * Written by {@see ProcessTranscriptWithAI} on success, from the
     * text it read rather than from whatever is on disk afterwards.
     */
    public function recordAnalysedTranscript(string $contentHash): void
    {
        $this->writeProcessingMetadata(static function (array $metadata) use ($contentHash): array {
            $metadata['analysed_transcript'] = [
                'hash' => $contentHash,
                'analysed_at' => now()->toIso8601String(),
            ];

            return $metadata;
        });
    }

    /**
     * Record the content of the full-service transcript this run now holds.
     *
     * The sibling of {@see recordTranscriptContent()}, one layer earlier.
     * That one tracks the *sermon* text, so a pass that rewrites the
     * full-service transcript without re-deriving the sermon — which is exactly
     * what repetition recovery does, since the spans it would derive from are
     * not settled until P8-Q15 — records nothing at all, and the run reads as
     * untouched. P8-Q12's closeout names that half as open; this is it.
     *
     * Silence still means unknown. Every run banked before this existed has no
     * stamp on either side, and {@see sermonDerivationIsOwed()} reads that as
     * "not known to be owed" rather than re-deriving the corpus on a guess.
     */
    public function recordServiceTranscriptContent(string $contentHash): void
    {
        $this->writeProcessingMetadata(static function (array $metadata) use ($contentHash): array {
            $metadata['service_transcript_content'] = [
                'hash' => $contentHash,
                'changed_at' => now()->toIso8601String(),
            ];

            return $metadata;
        });
    }

    /**
     * Record the full-service transcript the sermon text was actually sliced
     * from, written by the job that slices it.
     */
    public function recordSermonDerivedFrom(string $contentHash): void
    {
        $this->writeProcessingMetadata(static function (array $metadata) use ($contentHash): array {
            $metadata['sermon_derived_from_service_transcript'] = [
                'hash' => $contentHash,
                'derived_at' => now()->toIso8601String(),
            ];

            return $metadata;
        });
    }

    /**
     * Whether this run's sermon text is known to have been sliced from a
     * full-service transcript it no longer holds.
     *
     * False when either side is unrecorded, deliberately: reading silence as
     * owed would re-derive every run banked before the stamps existed. Only a
     * recorded change no recorded derivation has consumed counts.
     */
    public function sermonDerivationIsOwed(): bool
    {
        $metadata = $this->processing_metadata?->toArray() ?? [];

        $current = data_get($metadata, 'service_transcript_content.hash');

        if (! is_string($current) || $current === '') {
            return false;
        }

        $derived = data_get($metadata, 'sermon_derived_from_service_transcript.hash');

        return ! is_string($derived) || $derived !== $current;
    }

    /** The stable content hash of a full-service transcript's cue text. */
    public static function hashServiceTranscriptContent(ChurchServiceTranscript $transcript): string
    {
        return hash('sha256', implode("\n", array_map(
            static fn (array $cue): string => sprintf('%.3f|%.3f|%s', $cue['start'], $cue['end'], $cue['text']),
            $transcript->cues,
        )));
    }

    public static function hashTranscriptContent(string $text): string
    {
        return hash('sha256', trim($text));
    }

    /**
     * Whether this run's banked analysis is known to describe a transcript it no
     * longer holds.
     *
     * False when nothing was recorded, and deliberately so. Every run completed
     * before this was introduced has no recorded hash on either side, and
     * reading that silence as "owed" would re-dispatch a paid analysis for the
     * whole corpus. Only a recorded transcript change that no recorded analysis
     * has consumed counts — so the answer is evidence, never an assumption.
     */
    public function analysisIsOwed(): bool
    {
        $metadata = $this->processing_metadata?->toArray() ?? [];

        $current = data_get($metadata, 'transcript_content.hash');

        if (! is_string($current) || $current === '') {
            return false;
        }

        $analysed = data_get($metadata, 'analysed_transcript.hash');

        return ! is_string($analysed) || $analysed !== $current;
    }

    /**
     * The sha256 this run recorded for its source recording, from whichever
     * evidence it kept.
     *
     * The `file_hash` column is set by `UnifiedMediaProcessor` and holds for
     * every operation-2 and -3 run, but for **none of the 416 operation-4 runs**:
     * that lane records its sources in the approved manifest instead, one entry
     * per part with a path, size and sha256. The evidence is the same evidence,
     * so a restore that reads only the column cannot verify the newest and
     * largest operation at all.
     *
     * Falls back to the manifest only for a **single-part, unconcatenated**
     * import, and that restriction is the point rather than caution. A staged
     * source assembled from several archive parts is an ffmpeg concat, and its
     * bytes match no single archive file — so a manifest part's hash would prove
     * nothing about the file being restored. Where the fallback does apply,
     * staging is a plain copy: measured across the 2026-09-09 repetition cohort,
     * the staged file matched the recorded manifest size for 99 of 99 usable
     * runs and hashed identically in all three sampled.
     *
     * Note what the manifest hash does and does not establish. It was taken when
     * the manifest was approved and, per `sha256_basis`, not re-verified at
     * dispatch — so it proves the archive copy is the file the import approved,
     * which is exactly the question a restore asks.
     */
    public function recordedSourceFileHash(): ?string
    {
        if (is_string($this->file_hash) && $this->file_hash !== '') {
            return $this->file_hash;
        }

        $import = ($this->processing_metadata?->toArray() ?? [])['historic_import'] ?? [];

        if (! is_array($import) || ($import['concatenation'] ?? null) !== 'none') {
            return null;
        }

        $sources = $import['sources'] ?? null;

        if (! is_array($sources) || count($sources) !== 1 || ! is_array($sources[0] ?? null)) {
            return null;
        }

        $hash = $sources[0]['sha256'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * The archive path the manifest recorded for a single-part source, relative
     * to the archive root.
     *
     * Paired with {@see recordedSourceFileHash()} so a sweep can name the
     * candidate it intends to verify rather than guessing from the date.
     *
     * **Returned exactly as recorded, which is not uniformly relative.** Across
     * the 2026-09-09 repetition cohort, 41 of 50 are relative to the archive
     * root while nine are absolute — seven under the archive itself and two
     * under `/mnt/historic-work/calibration-corpus`, a different volume
     * entirely. A caller that blindly prefixes an archive root produces
     * `/mnt/cbc-services//mnt/...` and concludes the recording is gone, which
     * is indistinguishable from a reaped file. Resolve by testing for a leading
     * separator first.
     */
    public function recordedArchiveSourcePath(): ?string
    {
        $import = ($this->processing_metadata?->toArray() ?? [])['historic_import'] ?? [];

        if (! is_array($import) || ($import['concatenation'] ?? null) !== 'none') {
            return null;
        }

        $sources = $import['sources'] ?? null;

        if (! is_array($sources) || count($sources) !== 1 || ! is_array($sources[0] ?? null)) {
            return null;
        }

        $path = $sources[0]['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function clearReExtraction(): void
    {
        $this->writeProcessingMetadata(static function (array $metadata): array {
            unset($metadata['re_extraction']);

            return $metadata;
        });
    }

    /**
     * Hand the spent re-extraction authority forward to historic promotion.
     *
     * A re-cut passes two independent overwrite guards, not one. The first is
     * {@see SermonMetadataIntegrationService::organizeVideoFile()}, which stores
     * the new video over the pipeline copy. The second is
     * {@see HistoricProcessingResultAssetTransfer} promoting that copy into
     * permanent quarantine, where an existing destination holding different
     * bytes is a genuine conflict and refused. Both are right to refuse an
     * accidental rewrite, and both are wrong to refuse an operator's deliberate
     * re-cut.
     *
     * {@see self::isReExtraction()} is consumed by the first guard, so promotion
     * would otherwise never learn the replacement was intended and the run would
     * fail permanently with its correct new video stranded in staging. This
     * keeps the "one replacement, then the door closes" shape by spending the
     * request and issuing a narrower authority for exactly the next guard.
     */
    public function authoriseVideoReplacementOnPromotion(): void
    {
        $this->writeProcessingMetadata(static function (array $metadata): array {
            $metadata['re_extraction'] = [
                'replacement_authorised' => true,
                'authorised_at' => now()->toISOString(),
            ];

            return $metadata;
        });
    }

    /**
     * Whether historic promotion may replace this run's already-promoted sermon
     * video. See {@see self::authoriseVideoReplacementOnPromotion()}.
     */
    public function permitsPromotionVideoReplacement(): bool
    {
        return (bool) data_get(
            $this->processing_metadata?->toArray(),
            're_extraction.replacement_authorised'
        );
    }

    /**
     * The staging context this run's artifacts were written under, if it was dispatched
     * as part of an approved historic batch.
     *
     * The batch root lives in the staging disk's *root* — see
     * {@see HistoricStagingGuard::activate()} — not in the
     * artifact keys this record holds, so those keys only resolve while this context is
     * active. Any surface resuming or retrying the run must reactivate it first.
     *
     * @throws \RuntimeException If a recorded context is malformed.
     */
    public function historicStagingContext(): ?HistoricStagingContext
    {
        $context = data_get($this->processing_metadata?->toArray(), 'historic_import.staging_context');

        return is_array($context) ? HistoricStagingContext::fromArray($context) : null;
    }

    public function isDegradedCompletion(): bool
    {
        return $this->is_degraded_completion;
    }

    /**
     * @return array<string, mixed>
     */
    public function manualReviewMetadata(): array
    {
        $manualReview = $this->processing_metadata?->manualReview;

        if ($manualReview instanceof ProcessingManualReviewMetadata) {
            return $manualReview->toArray();
        }

        return $this->legacyManualReviewMetadata();
    }

    public function manuallyConfirmedSegmentId(): ?int
    {
        return $this->processing_metadata?->manualReview?->confirmedSegmentId;
    }

    /**
     * Duration observed in the emitted sermon media, in seconds.
     *
     * This value is populated from FFprobe after the emitted sermon video is
     * written. It is distinct from the planned sum returned by
     * extractedSermonMediaDuration(), and returns null for missing, unreadable
     * or non-positive metadata.
     */
    public function observedSermonMediaDuration(): ?float
    {
        $duration = data_get($this->processing_metadata?->toArray(), 'trim.observed_duration');

        if (! is_numeric($duration)) {
            return null;
        }

        $duration = (float) $duration;

        return is_finite($duration) && $duration > 0.0 ? $duration : null;
    }

    /**
     * Planned duration of the sermon extraction, in seconds.
     *
     * A concat plan joins several source spans across a gap, so this sums the
     * requested spans rather than inspecting the emitted media. The run bounds
     * stay the true source window (so segment_end_time remains a real livestream
     * timestamp). Use observedSermonMediaDuration() for the playable duration.
     */
    public function extractedSermonMediaDuration(): ?float
    {
        return $this->recordedSermonExtraction()['duration'] ?? null;
    }

    /**
     * The recorded sermon extraction plan's overall source span and planned
     * duration. `start`/`end` are the first span's start and the last span's
     * end (the true source window); `duration` sums the spans, excluding any
     * concat gap between them. Callers compare start/end against a Sermon's
     * current bounds to detect a later manual edit that invalidates the plan.
     *
     * @return array{start: float, end: float, duration: float}|null
     */
    public function recordedSermonExtraction(): ?array
    {
        $spans = $this->recordedSermonExtractionSpans();

        if ($spans === null) {
            return null;
        }

        $duration = 0.0;
        foreach ($spans as $span) {
            $duration += $span['end'] - $span['start'];
        }

        return [
            'start' => $spans[0]['start'],
            'end' => $spans[count($spans) - 1]['end'],
            'duration' => $duration,
        ];
    }

    /**
     * The ordered source spans the sermon media was actually cut from.
     *
     * A concat plan joins several spans and omits what lies between them, so
     * anything deriving from the emitted media — the sermon transcript above
     * all — must follow these spans rather than the run's outer bounds. Spans
     * of zero or negative length are dropped; a plan left with none reads as
     * absent, so callers fall back to the recorded bounds.
     *
     * @return list<array{start: float, end: float}>|null
     */
    public function recordedSermonExtractionSpans(): ?array
    {
        $segments = data_get($this->processing_metadata?->toArray(), 'sermon_extraction_plan.segments');

        if (! is_array($segments) || $segments === []) {
            return null;
        }

        $spans = [];

        foreach ($segments as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $start = (float) ($segment['start_time'] ?? 0.0);
            $end = (float) ($segment['end_time'] ?? 0.0);

            if ($end > $start) {
                $spans[] = ['start' => $start, 'end' => $end];
            }
        }

        return $spans === [] ? null : $spans;
    }

    public function requiresManualSermonReview(): bool
    {
        $manualReviewStatus = $this->processing_metadata?->manualReview?->status;

        if ($manualReviewStatus === 'required') {
            return $this->canUseManualSermonReview()
                && $this->status === ProcessingStatus::Failed
                && $this->current_step === 'manual_review_required';
        }

        if ($manualReviewStatus === 'confirmed') {
            return false;
        }

        return $this->legacyManualReviewReasonCode() !== null
            && $this->status === ProcessingStatus::Failed
            && $this->current_step === 'manual_review_required'
            && $this->processing_type === MediaType::Livestream;
    }

    /**
     * Return the current review signals that require the source to remain
     * available for a possible recut.
     *
     * A retired run has none. Retirement withdraws the result outright — the
     * sections drop out of every reader, the sermon row is gone, and the
     * identity is expected to be reprocessed from the replacement source — so
     * there is nothing left for a reviewer to decide and nothing this source
     * could be recut for. Reading it any other way pins the bytes forever:
     * {@see HistoricReviewSourceReclaimer} tests
     * `Failed` before it tests obligations, and retirement does not clear
     * `Failed` (D4, 2026-09-03).
     *
     * @return list<string>
     */
    public function reviewSourceRetentionReasons(): array
    {
        if ($this->isRetired()) {
            return [];
        }

        $reasons = [];

        if ($this->serviceSections()
            ->where('publication_status', ServiceSectionPublicationStatus::PendingApproval->value)
            ->exists()) {
            $reasons[] = 'pending_approval';
        }

        if ($this->serviceSections()->where('needs_manual_review', true)->exists()) {
            $reasons[] = 'needs_manual_review';
        }

        $qualityStatus = $this->videoQualityMetadata()['status'] ?? null;

        if ($qualityStatus === SermonVideoQualityStatus::NeedsReview->value
            || $qualityStatus === SermonVideoQualityStatus::NeedsReview) {
            $reasons[] = 'video_quality_needs_review';
        }

        if ($this->status === ProcessingStatus::Failed && $this->current_step === 'manual_review_required') {
            $reasons[] = 'manual_review_required';
        }

        if ($this->assertsUnconfirmedSermonAbsence()) {
            $reasons[] = 'sermon_absence_unconfirmed';
        }

        return $reasons;
    }

    /**
     * The detector's assertion that this run's service genuinely held no sermon,
     * or null when it made none.
     *
     * Rebuilt through {@see ServiceStructure} rather than read straight off the
     * key, so the reconciliation that drops an assertion sitting beside a
     * detected sermon section applies here too. Reading the key directly would
     * let a stray assertion stop the extraction of a sermon the same structure
     * says is there.
     */
    public function assertedSermonAbsence(): ?ServiceSermonAbsence
    {
        $structure = data_get($this->processing_metadata?->toArray() ?? [], 'service_structure');

        return is_array($structure) ? ServiceStructure::fromArray($structure)->sermonAbsence : null;
    }

    /**
     * Whether this run asserts sermon absence that no operator has confirmed.
     *
     * The source stays until someone has: absence is the one structural claim
     * that cannot be checked against the run's own output, because the output is
     * the absence. Run #935 read a whole morning service as "fragmentary opening
     * audio" and the very next run of the same recording completed with twenty
     * sections, so an unconfirmed verdict has to be watchable, not just readable
     * (D1, 2026-09-03).
     */
    public function assertsUnconfirmedSermonAbsence(): bool
    {
        if (! $this->assertedSermonAbsence() instanceof ServiceSermonAbsence) {
            return false;
        }

        $churchService = $this->churchService;

        return ! $churchService instanceof ChurchService
            || $churchService->occasion_confirmed_at === null;
    }

    public function hasUnresolvedReviewObligation(): bool
    {
        return $this->reviewSourceRetentionReasons() !== [];
    }

    public function videoProcessingMode(): string
    {
        if ($this->processing_type !== MediaType::Video) {
            return self::VIDEO_PROCESSING_MODE_FULL_VIDEO;
        }

        $mode = $this->processing_metadata?->videoProcessingMode;

        if ($mode === self::VIDEO_PROCESSING_MODE_AUTO_TRIM) {
            return self::VIDEO_PROCESSING_MODE_AUTO_TRIM;
        }

        return $this->processing_metadata?->trimRequested === true
            ? self::VIDEO_PROCESSING_MODE_AUTO_TRIM
            : self::VIDEO_PROCESSING_MODE_FULL_VIDEO;
    }

    /**
     * @return array<string, mixed>
     */
    public function videoQualityMetadata(): array
    {
        $metadata = $this->processing_metadata?->videoQuality;

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function putVideoQualityMetadata(array $metadata): void
    {
        $this->writeProcessingMetadata(static function (array $processingMetadata) use ($metadata): array {
            $processingMetadata['video_quality'] = $metadata;

            return $processingMetadata;
        });
    }

    /**
     * Temp-disk-relative path of the stored full-service transcript JSON, if
     * the LLM-first structure pipeline has produced one for this run.
     */
    public function serviceTranscriptPath(): ?string
    {
        $metadata = $this->processing_metadata?->toArray() ?? [];
        $path = $metadata['service_transcript_path'] ?? null;

        return is_string($path) && trim($path) !== '' ? $path : null;
    }

    /**
     * Whether the full-service transcript artifact both is recorded and still
     * exists on whichever disk holds it.
     *
     * Current runs write a durable `service-transcripts/…` key on the transcript
     * disk; runs completed before that recorded a temp-disk key which the daily
     * sweep may already have taken. ServiceArtifactDisk resolves both.
     */
    public function hasStoredServiceTranscript(): bool
    {
        $transcriptPath = $this->serviceTranscriptPath();

        if ($transcriptPath === null) {
            return false;
        }

        $artifactDisk = ServiceArtifactDisk::for($transcriptPath);

        return Storage::disk($artifactDisk)->exists($transcriptPath);
    }

    /**
     * Apply a change to `processing_metadata` against the row's **current**
     * stored state rather than this instance's snapshot.
     *
     * `processing_metadata` is one JSON column that many writers share, and a
     * pipeline job holds a single model instance across a whole step while other
     * collaborators write to the same row through fresh queries part-way
     * through it. Saving the in-memory snapshot afterwards silently discarded
     * everything they added.
     *
     * That was not a rare race but a total loss: `ServiceArtifactStorage` records
     * the `raw` transcription payload and the archived audio mid-step, and
     * `TranscribeFullService` then wrote its snapshot back — so **zero `raw`
     * artifacts existed across all 1,362 processing logs while 911 raw files sat
     * on disk**, invisible to the orphan audit that enumerates them from here.
     *
     * The mutation receives the fresh array and returns the array to store, so a
     * writer that removes a key expresses that as plainly as one that sets it.
     * The re-read narrows the window rather than closing it; these writes are
     * infrequent and sequential within a step, and a genuinely concurrent writer
     * still needs a column-level merge.
     *
     * Public because the writers that still need converting are not all on this
     * model: roughly thirty job- and service-level sites still read
     * `processing_metadata`, mutate the array and save a held instance, and each
     * is the same lost update waiting to happen. They convert to this.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    public function writeProcessingMetadata(callable $mutate): void
    {
        $stored = $this->exists
            ? static::query()->whereKey($this->getKey())->first()?->processing_metadata?->toArray()
            : null;

        $metadata = $mutate($stored ?? $this->processing_metadata?->toArray() ?? []);

        $this->forceFill(['processing_metadata' => $metadata])->save();
    }

    /**
     * Record the stored transcript together with both descriptions of what it
     * is worth as evidence.
     *
     * The windows say where the recording was looked at and yielded nothing;
     * the suspect blocks say where it yielded text that cannot be true
     * {@see ServiceTranscriptRepetitionScreen}.
     * All three are written in one pass because they describe the same
     * artifact: a metadata write that updated the path while leaving either
     * description behind would describe the transcript this run no longer holds.
     *
     * A null screen means *unknown*, and clears any screen already recorded
     * rather than leaving it: the caller is replacing the transcript, so a
     * screen it did not supply describes text this run no longer holds. An
     * empty array is the different claim that the transcript was screened and
     * nothing was found.
     *
     * @param  list<array{start: float, end: float, reason: string}>  $unobservableWindows
     * @param  list<SuspectTranscriptBlockShape>|null  $suspectBlocks
     */
    public function putServiceTranscriptPath(string $path, array $unobservableWindows = [], ?array $suspectBlocks = null): void
    {
        $this->writeProcessingMetadata(static function (array $metadata) use ($path, $unobservableWindows, $suspectBlocks): array {
            $metadata['service_transcript_path'] = $path;
            $metadata['service_transcript_unobservable_windows'] = $unobservableWindows;

            if ($suspectBlocks === null) {
                unset($metadata['service_transcript_suspect_blocks']);

                return $metadata;
            }

            $metadata['service_transcript_suspect_blocks'] = $suspectBlocks;

            return $metadata;
        });
    }

    /**
     * The blocks the repetition screen last recorded for this run's transcript.
     *
     * Null is *unknown*, not clean: absent for every run that completed before
     * the screen existed, and the 2026-09-09 correctness review found 125
     * historic sermons looping, none of which can carry a stamp. An empty array
     * is the positive claim that the transcript was screened and found clear.
     *
     * @return list<array<string, mixed>>|null
     */
    public function recordedTranscriptSuspectBlocks(): ?array
    {
        $blocks = ($this->processing_metadata?->toArray() ?? [])['service_transcript_suspect_blocks'] ?? null;

        return is_array($blocks) ? array_values(array_filter($blocks, 'is_array')) : null;
    }

    /**
     * Record that transcript recovery was replayed over this run, and what it
     * replaced.
     *
     * Written through the same safe path as the transcript key it accompanies,
     * because the two must not disagree: a read-modify-write here would be the
     * lost update {@see writeProcessingMetadata} exists to prevent, and would
     * name a superseded transcript that the row no longer points away from.
     *
     * @param  array<string, mixed>  $stamp
     */
    public function putTranscriptRecoveryReplay(array $stamp): void
    {
        $this->writeProcessingMetadata(static function (array $metadata) use ($stamp): array {
            $metadata['transcript_recovery_replay'] = $stamp;

            return $metadata;
        });
    }

    /**
     * Record the structure a re-detection is about to replace, or clear it.
     *
     * Written through the safe path because it is taken while the run is still
     * completed and other writers may be touching the same column; a lost
     * update here would leave a re-derived run with no record of what it had.
     *
     * @param  array<string, mixed>|null  $snapshot
     */
    public function putStructureRedetectionSnapshot(?array $snapshot): void
    {
        $this->writeProcessingMetadata(static function (array $metadata) use ($snapshot): array {
            if ($snapshot === null) {
                unset($metadata[RedetectStructureOnRecoveredEvidence::SNAPSHOT_KEY]);

                return $metadata;
            }

            $metadata[RedetectStructureOnRecoveredEvidence::SNAPSHOT_KEY] = $snapshot;

            return $metadata;
        });
    }

    /** @return array<string, mixed>|null */
    public function transcriptRecoveryReplay(): ?array
    {
        $stamp = ($this->processing_metadata?->toArray() ?? [])['transcript_recovery_replay'] ?? null;

        return is_array($stamp) ? $stamp : null;
    }

    /** @return list<array{start: float, end: float, reason: string}> */
    public function serviceTranscriptUnobservableWindows(): array
    {
        $metadata = $this->processing_metadata?->toArray() ?? [];
        $windows = $metadata['service_transcript_unobservable_windows'] ?? null;

        if (! is_array($windows)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $window): array => [
                'start' => (float) $window['start'],
                'end' => (float) $window['end'],
                'reason' => (string) $window['reason'],
            ],
            $windows,
        ));
    }

    /**
     * Whether AnalyzeSegments determined the source recording has no usable
     * audio at all — every RMS sample in the log reads digital silence
     * (`-inf`). The run still reaches a terminal Completed disposition (there
     * is nothing to extract), so downstream steps read this flag to skip
     * themselves rather than working from a transcript that cannot exist.
     */
    public function isExcludedSilentAudio(): bool
    {
        return $this->exclusionReason() === self::EXCLUSION_REASON_SOURCE_AUDIO_SILENT;
    }

    /**
     * Whether this run was excluded for any recorded reason. An excluded run is
     * terminal and is not revisited; the reason says why, and the disposition
     * reader reports `excluded` rather than the run's own status.
     */
    public function isExcluded(): bool
    {
        return $this->exclusionReason() !== null;
    }

    /**
     * The recorded exclusion reason, or null when this run was not excluded.
     * An unrecognised value reads as null rather than as some unknown exclusion.
     */
    public function exclusionReason(): ?string
    {
        $reason = data_get($this->processing_metadata?->toArray() ?? [], 'exclusion.reason');

        return is_string($reason) && in_array($reason, self::EXCLUSION_REASONS, true)
            ? $reason
            : null;
    }

    /**
     * The evidence recorded alongside a silent-audio exclusion — frame count,
     * the RMS log path, and (when available) the source file's historic-import
     * provenance — or null when the run was not excluded.
     *
     * @return array<string, mixed>|null
     */
    public function silentAudioExclusionEvidence(): ?array
    {
        return $this->exclusionEvidence();
    }

    /**
     * The evidence recorded alongside whichever exclusion applies, or null when
     * this run was not excluded.
     *
     * @return array<string, mixed>|null
     */
    public function exclusionEvidence(): ?array
    {
        $evidence = data_get($this->processing_metadata?->toArray() ?? [], 'exclusion.evidence');

        return is_array($evidence) ? $evidence : null;
    }

    /**
     * Record that this run was excluded because its source audio is
     * digitally silent, carrying the evidence for the operator to read back
     * without hand-diagnosing the run.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function putSilentAudioExclusion(array $evidence): void
    {
        $this->putExclusion(self::EXCLUSION_REASON_SOURCE_AUDIO_SILENT, $evidence);
    }

    /**
     * Record that this run was excluded, carrying the evidence for the operator
     * to read back without hand-diagnosing the run.
     *
     * @param  value-of<self::EXCLUSION_REASONS>  $reason
     * @param  array<string, mixed>  $evidence
     */
    public function putExclusion(string $reason, array $evidence): void
    {
        if (! in_array($reason, self::EXCLUSION_REASONS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown processing exclusion reason [%s].', $reason));
        }

        $this->writeProcessingMetadata(static function (array $processingMetadata) use ($reason, $evidence): array {
            $processingMetadata['exclusion'] = [
                'reason' => $reason,
                'recorded_at' => now()->toIso8601String(),
                'evidence' => $evidence,
            ];

            return $processingMetadata;
        });
    }

    /**
     * Whether this run has been retired: its result is withdrawn because the
     * source it read was replaced, and the identity is expected to be processed
     * again. Distinct from an exclusion, which is terminal.
     */
    public function isRetired(): bool
    {
        return $this->superseded_at !== null;
    }

    /**
     * The inventory recorded when this run was retired — what the withdrawn
     * sermon was and where its assets were moved to — or null when this run was
     * not retired through that path.
     *
     * @return array<string, mixed>|null
     */
    public function retirementRecord(): ?array
    {
        $record = data_get($this->processing_metadata?->toArray() ?? [], 'retirement');

        return is_array($record) ? $record : null;
    }

    /**
     * Record what this run's retirement withdrew, so the withdrawn sermon stays
     * identifiable after its row is gone.
     *
     * @param  array<string, mixed>  $record
     */
    public function putRetirement(array $record): void
    {
        $this->writeProcessingMetadata(static function (array $processingMetadata) use ($record): array {
            $processingMetadata['retirement'] = [
                'recorded_at' => now()->toIso8601String(),
            ] + $record;

            return $processingMetadata;
        });
    }

    public function isAutoTrimVideoRun(): bool
    {
        return $this->processing_type === MediaType::Video
            && $this->videoProcessingMode() === self::VIDEO_PROCESSING_MODE_AUTO_TRIM;
    }

    public function usesSegmentationPipeline(): bool
    {
        return $this->processing_type === MediaType::Livestream
            || $this->isAutoTrimVideoRun();
    }

    public function canUseManualSermonReview(): bool
    {
        return $this->usesSegmentationPipeline();
    }

    public static function makeDedupKey(string $fileHash, MediaType $mediaType, string $videoMode, ?int $ownerUserId): string
    {
        $callerScope = $ownerUserId === null ? 'system' : "user:{$ownerUserId}";

        return "{$fileHash}:{$callerScope}:{$mediaType->value}:{$videoMode}";
    }

    public function buildDedupKey(): ?string
    {
        if ($this->file_hash === null) {
            return null;
        }

        return self::makeDedupKey(
            $this->file_hash,
            $this->processing_type,
            $this->videoProcessingMode(),
            $this->owner_user_id
        );
    }

    public function processingPipelineProfile(): string
    {
        return match (true) {
            $this->processing_type === MediaType::Audio => 'audio',
            $this->processing_type === MediaType::Livestream => 'livestream',
            $this->isAutoTrimVideoRun() => 'video_auto_trim',
            default => 'video',
        };
    }

    // Accessors for backward compatibility

    /**
     * Accessor for stored_file_path (maps to source_file_path).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function storedFilePath(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->source_file_path,
            set: fn ($value) => ['source_file_path' => $value]
        );
    }

    /**
     * @return Attribute<string, string>
     */
    protected function originalFilename(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyManualReviewMetadata(): array
    {
        $reasonCode = $this->legacyManualReviewReasonCode();
        if ($reasonCode === null) {
            return [];
        }

        return [
            'status' => 'required',
            'reason_code' => $reasonCode,
            'reason_message' => $this->legacyManualReviewReasonMessage(),
            'flagged_at' => $this->updated_at?->toIso8601String(),
            'speech_segments' => [],
        ];
    }

    private function legacyManualReviewReasonCode(): ?string
    {
        $message = $this->legacyManualReviewReasonMessage();

        return match (true) {
            $message !== null && str_contains($message, 'No speech block met the 20-minute sermon threshold.') => 'no_qualifying_speech_block',
            $message !== null && str_contains($message, 'Multiple speech blocks met the 20-minute sermon threshold.') => 'multiple_qualifying_speech_blocks',
            $message !== null && str_contains($message, 'The longest speech block was not at least 1.5x longer than the next-longest speech block.') => 'ratio_below_threshold',
            $message !== null && str_contains($message, 'The recording is of the sermon alone, but the sermon is shorter than this service usually runs.') => 'sermon_shorter_than_typical',
            $message !== null && str_contains($message, 'Sermon auto-selection confidence was insufficient.') => 'manual_confidence_review',
            default => null,
        };
    }

    private function legacyManualReviewReasonMessage(): ?string
    {
        if (
            $this->processing_type !== MediaType::Livestream
            || $this->status !== ProcessingStatus::Failed
            || $this->current_step !== 'manual_review_required'
            || ! is_string($this->error_message)
            || $this->error_message === ''
        ) {
            return null;
        }

        return str_starts_with($this->error_message, 'Manual Review Note: ')
            ? substr($this->error_message, strlen('Manual Review Note: '))
            : $this->error_message;
    }

    /**
     * Get all temporary file paths associated with this processing run.
     *
     * @return list<string>
     */
    public function temporaryFilePaths(): array
    {
        $tempFiles = [];

        if (filled($this->source_file_path)) {
            $tempFiles[] = (string) $this->source_file_path;
        }

        if (filled($this->enhanced_audio_file_path)) {
            $tempFiles[] = (string) $this->enhanced_audio_file_path;
        }

        if (filled($this->video_file_path) && str_contains((string) $this->video_file_path, 'temp/')) {
            $tempFiles[] = (string) $this->video_file_path;
        }

        $metadata = $this->processing_metadata?->toArray() ?? [];

        // service_transcript_path is deliberately absent: the full-service
        // transcript is a small JSON artifact that `structure:evaluate
        // --processing-id` loads after the run completes, so run cleanup must
        // not delete it. Re-runs overwrite it in place (keyed by processing id).
        foreach (['extracted_segment_path', 'extracted_audio_path', 'temp_video_path'] as $key) {
            $path = $metadata[$key] ?? null;
            if (filled($path) && is_string($path)) {
                $tempFiles[] = $path;
            }
        }

        if ($this->processing_type === MediaType::Video) {
            $storedPath = $this->stored_file_path;
            if (filled($storedPath) && str_contains((string) $storedPath, 'temp/')) {
                $tempFiles[] = (string) $storedPath;
            }
        }

        return array_values(array_unique($tempFiles));
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'processing_id' => ['sometimes', 'required', 'string', 'size:36'],
            'processing_type' => ['sometimes', 'required', Rule::enum(MediaType::class)],
            'status' => ['sometimes', 'required', Rule::enum(ProcessingStatus::class)],
            'original_filename' => ['sometimes', 'required', 'string', 'max:255'],
            'file_hash' => ['nullable', 'string', 'max:64'],
            'file_size' => ['nullable', 'integer', 'min:0', 'max:9223372036854775807'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'extracted_date' => ['nullable', 'date'],
            'extracted_service' => ['nullable', Rule::enum(SermonService::class)],
            'sermon_start_time' => ['nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'sermon_end_time' => ['nullable', 'numeric', 'min:0', 'max:9999999.999', 'gte:sermon_start_time'],
            'sermon_id' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'exists:sermons,id'],
            'owner_user_id' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'exists:users,id'],
            'church_service_id' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807', 'exists:church_services,id'],
            'error_message' => ['nullable', 'string'],
            'current_step' => ['nullable', 'string', 'max:255'],
            'source_file_path' => ['nullable', 'string', 'max:255'],
            'stored_file_path' => ['nullable', 'string', 'max:255'],
            'audio_file_path' => ['nullable', 'string', 'max:255'],
            'video_file_path' => ['nullable', 'string', 'max:255'],
            'transcript_file_path' => ['nullable', 'string', 'max:255'],
            'enhanced_audio_file_path' => ['nullable', 'string', 'max:255'],
            'rms_log_path' => ['nullable', 'string', 'max:255'],
            'threshold_method' => ['nullable', 'string', 'max:255'],
            'adaptive_threshold' => ['nullable', 'numeric'],
            'queue_name' => ['nullable', 'string', 'max:255'],
            'job_id' => ['nullable', 'string', 'max:255'],
            'dedup_key' => ['nullable', 'string', 'max:128'],
            'attempt_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_degraded_completion' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    private static function legacyManualReviewReasonPatterns(): array
    {
        return [
            'No speech block met the 20-minute sermon threshold.',
            'Multiple speech blocks met the 20-minute sermon threshold.',
            'The longest speech block was not at least 1.5x longer than the next-longest speech block.',
            'Sermon auto-selection confidence was insufficient.',
        ];
    }
}
