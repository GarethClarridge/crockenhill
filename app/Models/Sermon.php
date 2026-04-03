<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ThumbnailMetadata;
use App\Data\ThumbnailMetadataCast;
use App\Enums\PreacherSource;
use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Presenters\SermonSitemapPresenter;
use Database\Factories\SermonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Sitemap\Contracts\Sitemapable;
use Spatie\Sitemap\Tags\Url;

/**
 * App\Models\Sermon
 *
 * @phpstan-type ThumbnailCandidate array{
 *     id: string,
 *     timestamp: float,
 *     score: float,
 *     plain_path: string,
 *     card_path?: string|null,
 *     overlay_path?: string|null,
 *     composition_mode?: string|null,
 *     foreground_extraction_method?: string|null,
 *     foreground_bounds?: array<string, int>,
 *     foreground_coverage?: float|null
 * }
 *
 * @property int $id
 * @property Carbon $date
 * @property ?SermonService $service
 * @property SermonContentType $content_type
 * @property string $audio_file_path
 * @property string $filetype
 * @property string $title
 * @property string $slug
 * @property ?string $reference
 * @property string $preacher
 * @property ?int $preacher_id
 * @property ?PreacherSource $preacher_source
 * @property ?float $preacher_confidence
 * @property bool $needs_preacher_review
 * @property ?string $series
 * @property array<int, string>|null $points
 * @property ?string $summary
 * @property bool $show_summary
 * @property bool $show_points
 * @property ?string $transcript_file_path
 * @property ?string $thumbnail_file_path
 * @property ?Carbon $thumbnail_generated_at
 * @property ThumbnailMetadata|null $thumbnail_metadata
 * @property ?string $livestream_processing_id
 * @property ?string $video_file_path
 * @property ?SermonSourceType $source_type
 * @property ?float $segment_start_time
 * @property ?float $segment_end_time
 * @property array<string, mixed>|null $livestream_metadata
 * @property ?string $bible_reference
 * @property ?bool $is_guest
 * @property ?string $notes
 * @property ?int $download_count
 * @property ?float $duration
 * @property ?float $audio_length
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property-read ?string $human_date
 * @property-read ?string $series_url
 * @property-read ?string $plain_thumbnail_file_path
 * @property-read ?string $card_thumbnail_file_path
 * @property-read list<ThumbnailCandidate> $thumbnail_candidates
 * @property-read ThumbnailCandidate|null $selected_thumbnail_candidate
 * @property-read ServiceSection|null $publishedServiceSection
 * @property-read MediaProcessingLog|null $latestProcessingLog
 *
 * @method static \Database\Factories\SermonFactory factory(...$parameters)
 * @method static Builder|Sermon newModelQuery()
 * @method static Builder|Sermon newQuery()
 * @method static Builder|Sermon query()
 * @method static Builder|Sermon last12Months()
 * @method static Builder|Sermon forService(\App\Enums\SermonService $serviceType)
 * @method static Builder|Sermon inSeries(string $seriesTitle)
 * @method static Builder|Sermon byPreacher(string $preacherName)
 * @method static Builder|Sermon automated()
 * @method static Builder|Sermon manual()
 * @method static Builder|Sermon processingCompleted()
 * @method static Builder|Sermon processingFailed()
 * @method static Builder|Sermon processingInProgress()
 * @method static Builder|Sermon withThumbnail()
 * @method static Builder|Sermon forPodcast()
 * @method static Builder|Sermon needsPreacherReview()
 * @method static Builder|Sermon whereSermon()
 * @method static Builder|Sermon whereChildrensTalk()
 *
 * @mixin \Eloquent
 */
class Sermon extends Model implements Sitemapable
{
    /** @use HasFactory<SermonFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'audio_file_path', // Renamed from 'filename' for consistency
        'filetype',
        'date',
        'service',
        'content_type',
        'slug',
        'series',
        'reference',
        'preacher',
        'preacher_id',
        'preacher_source',
        'preacher_confidence',
        'needs_preacher_review',
        'points',
        'summary', // AI-generated sermon summary
        'meta_description', // SEO meta description (auto-generated if empty)
        'show_summary', // Toggle to show/hide AI-generated summary
        'show_points', // Toggle to show/hide AI-generated points
        'transcript_file_path', // Renamed from 'transcript_path' for consistency
        'thumbnail_file_path', // Renamed from 'thumbnail_path' for consistency
        'thumbnail_generated_at', // Timestamp when thumbnail was generated
        'thumbnail_metadata', // Metadata about thumbnail generation
        'livestream_processing_id', // Link to livestream processing
        'video_file_path', // Path to sermon video file
        'source_type', // Source type: manual, livestream, upload
        'segment_start_time', // Start time of sermon segment in livestream
        'segment_end_time', // End time of sermon segment in livestream
        'duration', // Duration of the sermon in seconds
        'scripture_passage_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'points' => 'array',
            'service' => SermonService::class,
            'content_type' => SermonContentType::class,
            'segment_start_time' => 'float',
            'segment_end_time' => 'float',
            'thumbnail_generated_at' => 'datetime',
            'thumbnail_metadata' => ThumbnailMetadataCast::class,
            'show_summary' => 'boolean',
            'show_points' => 'boolean',
            'source_type' => SermonSourceType::class,
            'preacher_source' => PreacherSource::class,
            'preacher_confidence' => 'float',
            'needs_preacher_review' => 'boolean',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function humanDate(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->date->format('F j, Y')
        );
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function plainThumbnailFilePath(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->thumbnail_metadata?->plainThumbnailPath
        );
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function cardThumbnailFilePath(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $metadata = $this->thumbnail_metadata;

                if ($metadata === null) {
                    return null;
                }

                return $metadata->cardThumbnailPath ?? $metadata->plainThumbnailPath;
            }
        );
    }

    /**
     * @return Attribute<list<ThumbnailCandidate>, never>
     */
    protected function thumbnailCandidates(): Attribute
    {
        return Attribute::make(
            get: fn (): array => $this->thumbnail_metadata?->thumbnailCandidates ?: []
        );
    }

    /**
     * @return Attribute<ThumbnailCandidate|null, never>
     */
    protected function selectedThumbnailCandidate(): Attribute
    {
        return Attribute::make(
            get: fn (): ?array => $this->thumbnail_metadata?->selectedCandidate()
        );
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function seriesUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->series ? '/christ/sermons/series/'.Str::slug($this->series) : null
        );
    }

    /**
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeLast12Months(Builder $query): Builder
    {
        return $query->where('date', '>=', now()->subMonths(12)->startOfDay());
    }

    /**
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeForService(Builder $query, SermonService $serviceType): Builder
    {
        return $query->where('service', $serviceType);
    }

    /**
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeInSeries(Builder $query, string $seriesTitle): Builder
    {
        return $query->where('series', $seriesTitle);
    }

    /**
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeByPreacher(Builder $query, string $preacherName): Builder
    {
        return $query->where(function (Builder $builder) use ($preacherName): void {
            $builder->where('preacher', $preacherName)
                ->orWhereHas('preacherProfile', fn (Builder $preacherQuery): Builder => $preacherQuery->where('name', $preacherName));
        });
    }

    /**
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeNeedsPreacherReview(Builder $query): Builder
    {
        return $query->where('needs_preacher_review', true);
    }

    /**
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeWhereSermon(Builder $query): Builder
    {
        return $query->where('content_type', SermonContentType::Sermon);
    }

    /**
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeWhereChildrensTalk(Builder $query): Builder
    {
        return $query->where('content_type', SermonContentType::ChildrensTalk);
    }

    /**
     * Scope to filter sermons visible in the public sitemap.
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeWhereVisibleInSitemap(Builder $query): Builder
    {
        if ((bool) config('sermons.childrens_talks.public', false)) {
            return $query;
        }

        return $query->whereSermon();
    }

    /**
     * @return BelongsTo<ScripturePassage, $this>
     */
    public function scripturePassage(): BelongsTo
    {
        return $this->belongsTo(ScripturePassage::class);
    }

    /**
     * @return BelongsTo<Preacher, $this>
     */
    public function preacherProfile(): BelongsTo
    {
        return $this->belongsTo(Preacher::class, 'preacher_id');
    }

    public function displayPreacherName(): ?string
    {
        $preacherName = $this->relationLoaded('preacherProfile')
            ? $this->preacherProfile?->name
            : null;

        $preacherName = trim((string) ($preacherName ?? $this->preacher));

        return $preacherName !== '' ? $preacherName : null;
    }

    public function displayReference(): ?string
    {
        if ($this->relationLoaded('scripturePassage') && $this->scripturePassage instanceof ScripturePassage) {
            $displayReference = $this->scripturePassage->display_reference ?: $this->scripturePassage->normalized_reference;

            if (trim((string) $displayReference) !== '') {
                return $displayReference;
            }
        }

        $reference = trim((string) $this->reference);

        return $reference !== '' ? $reference : null;
    }

    /**
     * @return HasOne<ServiceSection, $this>
     */
    public function publishedServiceSection(): HasOne
    {
        return $this->hasOne(ServiceSection::class, 'published_sermon_id');
    }

    /**
     * Get the latest processing log for this sermon.
     *
     * @return HasOne<MediaProcessingLog, $this>
     */
    public function latestProcessingLog(): HasOne
    {
        return $this->hasOne(MediaProcessingLog::class, 'sermon_id')->latestOfMany();
    }

    /**
     * Scope to get only automated sermons
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeAutomated(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNotNull('transcript_file_path')
                ->orWhereHas('processingLogs');
        });
    }

    /**
     * Scope to get only manually created sermons
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('transcript_file_path')
                ->whereDoesntHave('processingLogs');
        });
    }

    /**
     * Scope to get sermons with completed processing
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeProcessingCompleted(Builder $query): Builder
    {
        return $query->whereHas('processingLogs', function (Builder $q): void {
            $q->where('status', ProcessingStatus::COMPLETED);
        });
    }

    /**
     * Scope to get sermons with failed processing
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeProcessingFailed(Builder $query): Builder
    {
        return $query->whereHas('processingLogs', function (Builder $q): void {
            $q->where('status', ProcessingStatus::FAILED);
        });
    }

    /**
     * Scope to get sermons currently being processed
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeProcessingInProgress(Builder $query): Builder
    {
        return $query->whereHas('processingLogs', function (Builder $q): void {
            $q->where('status', ProcessingStatus::PROCESSING);
        });
    }

    /**
     * Get the processing logs for this sermon.
     */
    /**
     * @return HasMany<MediaProcessingLog, $this>
     */
    public function processingLogs(): HasMany
    {
        return $this->hasMany(MediaProcessingLog::class);
    }

    /**
     * Get the livestream processing log for this sermon.
     */
    /**
     * @return BelongsTo<MediaProcessingLog, $this>
     */
    public function livestreamProcessing(): BelongsTo
    {
        return $this->belongsTo(MediaProcessingLog::class, 'livestream_processing_id', 'processing_id');
    }

    /**
     * Check if this sermon has a transcript available
     *
     * Performance Optimization: Trust the database column presence to avoid
     * expensive remote storage existence checks in performance-critical paths
     * like sermon listings and sitemap generation.
     *
     * @return bool True if transcript path is set
     */
    public function hasTranscript(): bool
    {
        return ! empty($this->transcript_file_path) && trim((string) $this->transcript_file_path) !== '';
    }

    /**
     * Check if this sermon has a thumbnail available
     *
     * Performance Optimization: Trust the database column presence to avoid
     * expensive remote storage existence checks in performance-critical paths
     * like sermon listings and sitemap generation.
     *
     * @return bool True if thumbnail path is set
     */
    public function hasThumbnail(): bool
    {
        return ! empty($this->thumbnail_file_path) && trim((string) $this->thumbnail_file_path) !== '';
    }

    public function hasPlainThumbnail(): bool
    {
        return $this->plain_thumbnail_file_path !== null;
    }

    public function hasCardThumbnail(): bool
    {
        return $this->card_thumbnail_file_path !== null;
    }

    public function hasThumbnailCandidates(): bool
    {
        return $this->thumbnail_candidates !== [];
    }

    /**
     * @return ThumbnailCandidate|null
     */
    public function findThumbnailCandidate(string $candidateId): ?array
    {
        return $this->thumbnail_metadata?->findCandidate($candidateId);
    }

    /**
     * Get the full path to the transcript file
     *
     * @return string|null The full storage path or null if not set
     */
    public function getTranscriptPath(): ?string
    {
        return $this->transcript_file_path;
    }

    /**
     * Set the transcript path for this sermon
     *
     * @param  string|null  $path  The storage path to the transcript file
     */
    public function setTranscriptPath(?string $path): void
    {
        $this->transcript_file_path = $path;
    }

    /**
     * Check if this sermon was created through automated processing
     *
     * @return bool True if sermon was automatically processed
     */
    public function isAutomated(): bool
    {
        /**
         * Performance Optimization: Check if relationship is already loaded to prevent N+1 queries.
         */
        if ($this->relationLoaded('latestProcessingLog')) {
            return ! empty($this->transcript_file_path) || $this->latestProcessingLog !== null;
        }

        if ($this->relationLoaded('processingLogs')) {
            return ! empty($this->transcript_file_path) || $this->processingLogs->isNotEmpty();
        }

        return ! empty($this->transcript_file_path) || $this->processingLogs()->exists();
    }

    /**
     * Check if this sermon was created manually
     *
     * @return bool True if sermon was manually created
     */
    public function isManual(): bool
    {
        return ! $this->isAutomated();
    }

    /**
     * Get the current processing status for this sermon
     *
     * @return ProcessingStatus|null The current processing status or null if not automated
     */
    public function getProcessingStatus(): ?ProcessingStatus
    {
        return $this->latestProcessingLog?->status;
    }

    /**
     * Check if processing is complete for this sermon
     *
     * @return bool True if processing is completed
     */
    public function isProcessingComplete(): bool
    {
        $status = $this->getProcessingStatus();

        return $status?->isComplete() ?? false;
    }

    /**
     * Check if processing has failed for this sermon
     *
     * @return bool True if processing has failed
     */
    public function isProcessingFailed(): bool
    {
        $status = $this->getProcessingStatus();

        return $status?->isFailed() ?? false;
    }

    /**
     * Check if processing is currently in progress for this sermon
     *
     * @return bool True if processing is in progress
     */
    public function isProcessingInProgress(): bool
    {
        $status = $this->getProcessingStatus();

        return $status?->isInProgress() ?? false;
    }

    /**
     * Get the latest processing log for this sermon
     *
     * @return MediaProcessingLog|null The latest processing log or null
     */
    public function getLatestProcessingLog(): ?MediaProcessingLog
    {
        return $this->latestProcessingLog;
    }

    /**
     * Check if this sermon came from a livestream
     *
     * @return bool True if sermon was extracted from livestream
     */
    public function isFromLivestream(): bool
    {
        return $this->source_type === SermonSourceType::Livestream;
    }

    /**
     * Check if this sermon has an associated video file
     *
     * @return bool True if video file exists
     */
    public function hasVideo(): bool
    {
        return ! empty($this->video_file_path);
    }

    /**
     * Scope to get only livestream sermons
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeFromLivestream(Builder $query): Builder
    {
        return $query->where('source_type', SermonSourceType::Livestream);
    }

    /**
     * Scope to get sermons with video files
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeWithVideo(Builder $query): Builder
    {
        return $query->whereNotNull('video_file_path');
    }

    /**
     * Scope to get sermons by source type
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeBySourceType(Builder $query, SermonSourceType $sourceType): Builder
    {
        return $query->where('source_type', $sourceType);
    }

    /**
     * Scope to get sermons with thumbnails
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeWithThumbnail(Builder $query): Builder
    {
        return $query->whereNotNull('thumbnail_file_path');
    }

    /**
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeOrderByPreacherName(Builder $query, string $direction = 'asc'): Builder
    {
        return $query
            ->orderBy(
                Preacher::query()
                    ->select('name')
                    ->whereColumn('preachers.id', 'sermons.preacher_id')
                    ->limit(1),
                $direction
            )
            ->orderBy('preacher', $direction);
    }

    /**
     * Get the SEO meta description for the sermon.
     * Auto-generates from summary or title if not explicitly set.
     *
     * @return Attribute<string, never>
     */
    protected function metaDescription(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if (! empty($this->attributes['meta_description'])) {
                    return $this->attributes['meta_description'];
                }

                $preacherName = $this->displayPreacherName() ?? 'Unknown preacher';
                $description = "Listen to '{$this->title}' by {$preacherName}";
                $description .= " preached on {$this->human_date}";

                if ($this->displayReference()) {
                    $description .= ' - '.$this->displayReference();
                }

                $summary = null;
                if ($this->show_summary && $this->summary) {
                    $summary = trim(strip_tags($this->summary));
                }

                $seriesSuffix = $this->series ? " (Part of the {$this->series} series)" : '';

                if ($summary === null || $summary === '') {
                    return Str::limit($description.$seriesSuffix, 155);
                }

                $descriptionWithSeries = $description.$seriesSuffix;
                $separator = '. ';

                if (Str::length($descriptionWithSeries.$separator.$summary) <= 155) {
                    return $descriptionWithSeries.$separator.$summary;
                }

                $remainingSummaryLength = 155 - Str::length($description) - Str::length($separator);

                if ($remainingSummaryLength > 0) {
                    return $description.$separator.Str::limit($summary, $remainingSummaryLength);
                }

                return Str::limit($descriptionWithSeries, 155);
            }
        );
    }

    /**
     * Convert the sermon to a sitemap tag.
     *
     * @return Url|string|array<string, mixed>
     */
    public function toSitemapTag(): Url|string|array
    {
        return app(SermonSitemapPresenter::class)->toSitemapTag($this);
    }

    /**
     * Scope for podcast-ready sermons (must have audio file)
     *
     * @param  Builder<Sermon>  $query
     * @return Builder<Sermon>
     */
    public function scopeForPodcast(Builder $query): Builder
    {
        return $query->whereNotNull('audio_file_path')
            ->where('audio_file_path', '!=', '')
            ->orderBy('date', 'desc');
    }
}
