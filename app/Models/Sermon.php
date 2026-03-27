<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ThumbnailMetadata;
use App\Data\ThumbnailMetadataCast;
use App\Enums\PreacherSource;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use Illuminate\Database\Eloquent\Builder;
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
 * @property ?\Illuminate\Support\Carbon $thumbnail_generated_at
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
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property-read ?string $human_date
 * @property-read ?string $series_url
 * @property-read ?string $plain_thumbnail_file_path
 * @property-read ServiceSection|null $publishedServiceSection
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
    /** @use HasFactory<\Database\Factories\SermonFactory> */
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

    public function getHumanDateAttribute(): ?string
    {
        return $this->date->format('F j, Y');
    }

    public function getPlainThumbnailFilePathAttribute(): ?string
    {
        return $this->thumbnail_metadata?->plainThumbnailPath;
    }

    public function getSeriesUrlAttribute(): ?string
    {
        return $this->series ? '/christ/sermons/series/'.Str::slug($this->series) : null;
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
            $q->where('status', \App\Enums\ProcessingStatus::COMPLETED);
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
            $q->where('status', \App\Enums\ProcessingStatus::FAILED);
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
            $q->where('status', \App\Enums\ProcessingStatus::PROCESSING);
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
     * Get the latest processing log for this sermon.
     *
     * @return HasOne<MediaProcessingLog, $this>
     */
    public function latestProcessingLog(): HasOne
    {
        return $this->hasOne(MediaProcessingLog::class)->latestOfMany();
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
        return ! empty($this->transcript_file_path) || $this->getLatestProcessingLog() !== null;
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
     * @return \App\Enums\ProcessingStatus|null The current processing status or null if not automated
     */
    public function getProcessingStatus(): ?\App\Enums\ProcessingStatus
    {
        return $this->getLatestProcessingLog()?->status;
    }

    /**
     * Check if processing is complete for this sermon
     *
     * @return bool True if processing is completed
     */
    public function isProcessingComplete(): bool
    {
        return $this->getProcessingStatus()?->isComplete() ?? false;
    }

    /**
     * Check if processing has failed for this sermon
     *
     * @return bool True if processing has failed
     */
    public function isProcessingFailed(): bool
    {
        return $this->getProcessingStatus()?->isFailed() ?? false;
    }

    /**
     * Check if processing is currently in progress for this sermon
     *
     * @return bool True if processing is in progress
     */
    public function isProcessingInProgress(): bool
    {
        return $this->getProcessingStatus()?->isInProgress() ?? false;
    }

    /**
     * Get the latest processing log for this sermon.
     *
     * Performance Optimization: Intelligently handles eager loading of both
     * the collection relationship and the latestOfMany relationship to avoid
     * redundant DB queries.
     *
     * @return MediaProcessingLog|null The latest processing log or null
     */
    public function getLatestProcessingLog(): ?MediaProcessingLog
    {
        if ($this->relationLoaded('latestProcessingLog')) {
            return $this->latestProcessingLog;
        }

        if ($this->relationLoaded('processingLogs')) {
            /** @var \App\Models\MediaProcessingLog|null */
            return $this->processingLogs->sortByDesc('created_at')->first();
        }

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
     */
    public function getMetaDescriptionAttribute(): string
    {
        // Return explicitly set meta description if it exists
        if (! empty($this->attributes['meta_description'])) {
            return $this->attributes['meta_description'];
        }

        // Auto-generate from available content
        $preacherName = $this->displayPreacherName() ?? 'Unknown preacher';
        $description = "Listen to '{$this->title}' by {$preacherName}";
        $description .= " preached on {$this->human_date}";

        if ($this->displayReference()) {
            $description .= ' - '.$this->displayReference();
        }

        if ($this->series) {
            $description .= " (Part of the {$this->series} series)";
        }

        // Add excerpt from summary if available
        if ($this->show_summary && $this->summary) {
            $excerpt = Str::limit(strip_tags($this->summary), 80);
            $description .= ". {$excerpt}";
        }

        // Truncate to 155 characters (SEO best practice)
        return Str::limit($description, 155);
    }

    /**
     * Convert the sermon to a sitemap tag.
     *
     * @return Url|string|array<string, mixed>
     */
    public function toSitemapTag(): Url|string|array
    {
        return app(\App\Presenters\SermonSitemapPresenter::class)->toSitemapTag($this);
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
