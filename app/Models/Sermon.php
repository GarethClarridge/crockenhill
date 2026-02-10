<?php

namespace App\Models;

use App\Enums\SermonService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory; // For scope return types
// use Spatie\Feed\Feedable; // Not used in this file
// use Spatie\Feed\FeedItem; // Not used in this file
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon; // For type hinting Carbon instances
use Illuminate\Support\Str; // Added Enum import
use Spatie\Sitemap\Contracts\Sitemapable;
use Spatie\Sitemap\Tags\Url;

/**
 * App\Models\Sermon
 *
 * @property int $id
 * @property Carbon $date
 * @property ?SermonService $service
 * @property string $audio_file_path
 * @property string $filetype
 * @property string $title
 * @property string $slug
 * @property ?string $reference
 * @property string $preacher
 * @property ?string $series
 * @property ?array $points
 * @property ?string $summary
 * @property bool $show_summary
 * @property bool $show_points
 * @property ?string $transcript_file_path
 * @property ?string $thumbnail_file_path
 * @property ?\Illuminate\Support\Carbon $thumbnail_generated_at
 * @property ?array $thumbnail_metadata
 * @property ?string $livestream_processing_id
 * @property ?string $video_file_path
 * @property ?string $source_type
 * @property ?float $segment_start_time
 * @property ?float $segment_end_time
 * @property ?array $livestream_metadata
 * @property ?string $bible_reference
 * @property ?string $audio_url
 * @property ?bool $is_guest
 * @property ?string $notes
 * @property ?int $download_count
 * @property ?float $duration
 * @property ?float $audio_length
 * @property ?\Illuminate\Support\Carbon $created_at
 * @property ?\Illuminate\Support\Carbon $updated_at
 * @property-read ?string $human_date
 * @property-read ?string $series_url
 * @property-read ?string $preacher_url
 * @property-read ?string $video_url
 * @property-read ?string $thumbnail_url
 * @property-read string $itunes_duration
 * @property-read string $podcast_summary
 * @property-read string $rss_pub_date
 * @property-read string $canonical_url
 * @property-read ?string $episode_image_url
 * @property-read ?string $transcript_url
 * @property-read string $filename (deprecated, use audio_file_path)
 * @property-read ?string $thumbnail_path (deprecated, use thumbnail_file_path)
 *
 * @method static \Database\Factories\SermonFactory factory(...$parameters)
 * @method static Builder|Sermon newModelQuery()
 * @method static Builder|Sermon newQuery()
 * @method static Builder|Sermon query()
 * @method static Builder|Sermon last12Months()
 * @method static Builder|Sermon forService(string $serviceType)
 * @method static Builder|Sermon inSeries(string $seriesTitle)
 * @method static Builder|Sermon byPreacher(string $preacherName)
 * @method static Builder|Sermon automated()
 * @method static Builder|Sermon manual()
 * @method static Builder|Sermon processingCompleted()
 * @method static Builder|Sermon processingFailed()
 * @method static Builder|Sermon processingInProgress()
 * @method static Builder|Sermon withThumbnail()
 * @method static Builder|Sermon forPodcast()
 *
 * @mixin \Eloquent
 */
class Sermon extends Model implements Sitemapable
{
    use HasFactory;

    public $timestamps = false;

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
        'slug',
        'series',
        'reference',
        'preacher',
        'points', // Stored as JSON string, handled by accessor/mutator potentially
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
            'segment_start_time' => 'float',
            'segment_end_time' => 'float',
            'thumbnail_generated_at' => 'datetime',
            'thumbnail_metadata' => 'array',
            'show_summary' => 'boolean',
            'show_points' => 'boolean',
        ];
    }

    // Accessor for points is no longer strictly needed if 'points' => 'array' cast is used.
    // Eloquent's 'array' cast will handle JSON decode/encode.
    // If custom logic beyond simple JSON (like specific object mapping) was needed, accessor/mutator is good.
    // For now, relying on 'array' cast simplifies.
    // public function getPointsAttribute($value): ?array
    // {
    //   if (is_string($value)) {
    //     $decoded = json_decode($value, true);
    //     return (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
    //   }
    //   return is_array($value) ? $value : null; // Ensure it's an array or null
    // }

    // public function setPointsAttribute($value): void
    // {
    //   $this->attributes['points'] = is_array($value) ? json_encode($value) : null;
    // }

    public function getHumanDateAttribute(): ?string
    {
        return $this->date->format('F j, Y');
    }

    public function getAudioUrlAttribute(): ?string
    {
        if (! $this->audio_file_path) {
            return null;
        }

        $storageService = app(\App\Services\SermonStorageService::class);

        return $storageService->getPublicUrl($this);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_file_path) {
            return null;
        }

        $disk = config('thumbnail-generation.storage.disk', 'public');

        return \Illuminate\Support\Facades\Storage::disk($disk)->url($this->thumbnail_file_path);
    }

    public function getSeriesUrlAttribute(): ?string
    {
        return $this->series ? '/christ/sermons/series/'.Str::slug($this->series) : null;
    }

    public function getPreacherUrlAttribute(): ?string
    {
        return $this->preacher ? '/christ/sermons/preachers/'.Str::slug($this->preacher) : null;
    }

    public function scopeLast12Months(Builder $query): Builder
    {
        return $query->where('date', '>=', now()->subMonths(12)->startOfDay());
    }

    public function scopeForService(Builder $query, string $serviceType): Builder
    {
        return $query->where('service', $serviceType);
    }

    public function scopeInSeries(Builder $query, string $seriesTitle): Builder
    {
        return $query->where('series', $seriesTitle);
    }

    public function scopeByPreacher(Builder $query, string $preacherName): Builder
    {
        return $query->where('preacher', $preacherName);
    }

    /**
     * Scope to get only automated sermons
     */
    public function scopeAutomated(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNotNull('transcript_file_path')
                ->orWhereHas('processingLogs');
        });
    }

    /**
     * Scope to get only manually created sermons
     */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('transcript_file_path')
                ->whereDoesntHave('processingLogs');
        });
    }

    /**
     * Scope to get sermons with completed processing
     */
    public function scopeProcessingCompleted(Builder $query): Builder
    {
        return $query->whereHas('processingLogs', function ($q) {
            $q->where('status', \App\Enums\ProcessingStatus::COMPLETED);
        });
    }

    /**
     * Scope to get sermons with failed processing
     */
    public function scopeProcessingFailed(Builder $query): Builder
    {
        return $query->whereHas('processingLogs', function ($q) {
            $q->where('status', \App\Enums\ProcessingStatus::FAILED);
        });
    }

    /**
     * Scope to get sermons currently being processed
     */
    public function scopeProcessingInProgress(Builder $query): Builder
    {
        return $query->whereHas('processingLogs', function ($q) {
            $q->where('status', \App\Enums\ProcessingStatus::PROCESSING);
        });
    }

    /**
     * Get the processing logs for this sermon.
     */
    public function processingLogs(): HasMany
    {
        return $this->hasMany(MediaProcessingLog::class);
    }

    /**
     * Get the livestream processing log for this sermon.
     */
    public function livestreamProcessing(): BelongsTo
    {
        return $this->belongsTo(MediaProcessingLog::class, 'livestream_processing_id');
    }

    /**
     * Get the transcript content for this sermon
     *
     * @return string|null The transcript content or null if not available
     */
    public function getTranscriptAttribute(): ?string
    {
        if (! $this->transcript_file_path) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Storage::get($this->transcript_file_path);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to read transcript file', [
                'sermon_id' => $this->id,
                'transcript_file_path' => $this->transcript_file_path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if this sermon has a transcript available
     *
     * @return bool True if transcript exists and is readable
     */
    public function hasTranscript(): bool
    {
        if (! $this->transcript_file_path) {
            return false;
        }

        return \Illuminate\Support\Facades\Storage::exists($this->transcript_file_path);
    }

    /**
     * Check if this sermon has a thumbnail available
     *
     * @return bool True if thumbnail exists and is accessible
     */
    public function hasThumbnail(): bool
    {
        if (! $this->thumbnail_file_path) {
            return false;
        }

        $disk = config('thumbnail-generation.storage.disk', 'public');

        return \Illuminate\Support\Facades\Storage::disk($disk)->exists($this->thumbnail_file_path);
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
     * @return \App\Enums\ProcessingStatus|null The current processing status or null if not automated
     */
    public function getProcessingStatus(): ?\App\Enums\ProcessingStatus
    {
        /** @var \App\Models\MediaProcessingLog|null $latestLog */
        $latestLog = $this->processingLogs()->latest()->first();

        return $latestLog?->status;
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
        /** @var \App\Models\MediaProcessingLog|null */
        return $this->processingLogs()->latest()->first();
    }

    /**
     * Check if this sermon came from a livestream
     *
     * @return bool True if sermon was extracted from livestream
     */
    public function isFromLivestream(): bool
    {
        return $this->source_type === 'livestream';
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
     * Get the video URL for this sermon
     *
     * @return string|null The video URL or null if no video
     */
    public function getVideoUrlAttribute(): ?string
    {
        if (! $this->video_file_path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk(config('media-processing.storage.sermon_disk'))->url($this->video_file_path);
    }

    /**
     * Get the segment duration for livestream sermons
     *
     * @return float|null Duration in seconds or null if not from livestream
     */
    public function getSegmentDuration(): ?float
    {
        if (! $this->isFromLivestream() || ! $this->segment_start_time || ! $this->segment_end_time) {
            return null;
        }

        return $this->segment_end_time - $this->segment_start_time;
    }

    /**
     * Get formatted segment duration
     *
     * @return string|null Formatted duration or null
     */
    public function getSegmentDurationFormatted(): ?string
    {
        $duration = $this->getSegmentDuration();

        if ($duration === null) {
            return null;
        }

        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        return sprintf('%dm %ds', $minutes, $seconds);
    }

    /**
     * Get livestream metadata with defaults
     *
     * @return array The livestream metadata array
     */
    public function getLivestreamInfo(): array
    {
        if (! $this->isFromLivestream()) {
            return [];
        }

        return [
            'processing_id' => $this->livestream_processing_id,
            'original_filename' => optional($this->livestreamProcessing)->original_filename,
            'segment_start_time' => $this->segment_start_time,
            'segment_end_time' => $this->segment_end_time,
            'segment_duration' => $this->getSegmentDuration(),
            'segment_duration_formatted' => $this->getSegmentDurationFormatted(),
            'has_video' => $this->hasVideo(),
            'video_url' => $this->getVideoUrlAttribute(),
        ];
    }

    /**
     * Scope to get only livestream sermons
     */
    public function scopeFromLivestream(Builder $query): Builder
    {
        return $query->where('source_type', 'livestream');
    }

    /**
     * Scope to get sermons with video files
     */
    public function scopeWithVideo(Builder $query): Builder
    {
        return $query->whereNotNull('video_file_path');
    }

    /**
     * Scope to get sermons by source type
     */
    public function scopeBySourceType(Builder $query, string $sourceType): Builder
    {
        return $query->where('source_type', $sourceType);
    }

    /**
     * Scope to get sermons with thumbnails
     */
    public function scopeWithThumbnail(Builder $query): Builder
    {
        return $query->whereNotNull('thumbnail_file_path');
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
        $description = "Listen to '{$this->title}' by {$this->preacher}";
        $description .= " preached on {$this->human_date}";

        if ($this->reference) {
            $description .= " - {$this->reference}";
        }

        if ($this->series) {
            $description .= " (Part of the {$this->series} series)";
        }

        // Add excerpt from summary if available
        if ($this->summary) {
            $excerpt = Str::limit(strip_tags($this->summary), 80);
            $description .= ". {$excerpt}";
        }

        // Truncate to 155 characters (SEO best practice)
        return Str::limit($description, 155);
    }

    /**
     * Convert the sermon to a sitemap tag.
     */
    public function toSitemapTag(): Url|string|array
    {
        $year = $this->date->format('Y');
        $month = $this->date->format('m');

        // Calculate priority based on recency (use absolute value for past dates)
        $daysOld = abs(now()->diffInDays($this->date, false));
        $priority = $daysOld < 30 ? 0.8 : 0.6;

        // Change frequency based on age
        $changeFreq = $daysOld < 365
            ? Url::CHANGE_FREQUENCY_MONTHLY
            : Url::CHANGE_FREQUENCY_YEARLY;

        // Use updated_at if valid, otherwise fall back to date
        // Note: old records may have invalid updated_at values (0000-00-00) that aren't null
        // Also, timestamps are disabled so updated_at returns as string, not Carbon
        $lastModified = $this->date;
        if ($this->updated_at) {
            $updatedAt = \Carbon\Carbon::parse($this->updated_at);
            if ($updatedAt->year > 0) {
                $lastModified = $updatedAt;
            }
        }

        $url = Url::create("/christ/sermons/{$year}/{$month}/{$this->slug}")
            ->setLastModificationDate($lastModified)
            ->setChangeFrequency($changeFreq)
            ->setPriority($priority);

        if ($this->hasVideo() && $this->video_url) {
            $thumbnailUrl = $this->thumbnail_url;
            if ($thumbnailUrl) {
                $videoOptions = [];
                if ($this->duration && $this->duration > 0) {
                    $videoOptions['duration'] = (int) $this->duration;
                }
                $url->addVideo(
                    $thumbnailUrl,
                    $this->title,
                    $this->summary ?? $this->title,
                    $this->video_url,
                    null,
                    $videoOptions
                );
            }
        }

        if ($this->hasThumbnail() && $this->thumbnail_url) {
            $url->addImage(
                $this->thumbnail_url,
                $this->meta_description, // Caption
                '', // Geo location
                $this->title // Title
            );
        }

        return $url;
    }

    // ========================================================================
    // Podcast Feed Accessors
    // ========================================================================

    /**
     * Get duration formatted for iTunes podcast feeds (HH:MM:SS)
     */
    public function getItunesDurationAttribute(): string
    {
        $seconds = (int) ($this->duration ?? 0);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    /**
     * Get podcast episode summary from available metadata
     */
    public function getPodcastSummaryAttribute(): string
    {
        $parts = [];

        if ($this->reference) {
            $parts[] = "A sermon on {$this->reference}";
        }

        if ($this->preacher) {
            $prefix = empty($parts) ? 'A sermon from' : 'from';
            $parts[] = "{$prefix} {$this->preacher}";
        }

        if ($this->series) {
            $parts[] = "as part of our {$this->series} series";
        }

        return ! empty($parts) ? implode(' ', $parts).'.' : $this->title;
    }

    /**
     * Get RFC 2822 formatted date for RSS pubDate
     */
    public function getRssPubDateAttribute(): string
    {
        return $this->date->toRfc2822String();
    }

    /**
     * Get canonical sermon URL
     */
    public function getCanonicalUrlAttribute(): string
    {
        $year = $this->date->format('Y');
        $month = $this->date->format('m');

        return url("/christ/sermons/{$year}/{$month}/{$this->slug}");
    }

    /**
     * Scope for podcast-ready sermons (must have audio file)
     */
    public function scopeForPodcast(Builder $query): Builder
    {
        return $query->whereNotNull('audio_file_path')
            ->where('audio_file_path', '!=', '')
            ->orderBy('date', 'desc');
    }

    /**
     * Get episode image URL for podcast feeds
     * Uses thumbnail if available, otherwise returns null (template falls back to podcast artwork)
     */
    public function getEpisodeImageUrlAttribute(): ?string
    {
        return $this->thumbnail_url;
    }

    /**
     * Get transcript URL for podcast feeds (Podcast 2.0 transcript support)
     * Returns null if no transcript is available
     */
    public function getTranscriptUrlAttribute(): ?string
    {
        if (! $this->transcript_file_path) {
            return null;
        }

        // Generate a public URL to the transcript
        // This assumes transcripts are stored in a publicly accessible location
        return url("/christ/sermons/{$this->date->format('Y')}/{$this->date->format('m')}/{$this->slug}/transcript");
    }

    // ========================================================================
    // Backward Compatibility Accessors (Deprecated - will be removed in future)
    // ========================================================================

    /**
     * Backward compatibility accessor for 'filename' (deprecated)
     *
     * @deprecated Use audio_file_path instead
     */
    public function getFilenameAttribute(): ?string
    {
        return $this->attributes['audio_file_path'] ?? null;
    }

    /**
     * Backward compatibility mutator for 'filename' (deprecated)
     *
     * @deprecated Use audio_file_path instead
     */
    public function setFilenameAttribute(?string $value): void
    {
        $this->attributes['audio_file_path'] = $value;
    }

    /**
     * Backward compatibility accessor for 'thumbnail_path' (deprecated)
     *
     * @deprecated Use thumbnail_file_path instead
     */
    public function getThumbnailPathAttribute(): ?string
    {
        return $this->attributes['thumbnail_file_path'] ?? null;
    }

    /**
     * Backward compatibility mutator for 'thumbnail_path' (deprecated)
     *
     * @deprecated Use thumbnail_file_path instead
     */
    public function setThumbnailPathAttribute(?string $value): void
    {
        $this->attributes['thumbnail_file_path'] = $value;
    }
}
