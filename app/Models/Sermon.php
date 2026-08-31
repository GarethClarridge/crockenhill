<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ThumbnailMetadata;
use App\Data\ThumbnailMetadataCast;
use App\Enums\MediaType;
use App\Enums\PreacherSource;
use App\Enums\SermonContentType;
use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Enums\SermonTitleProvenance;
use App\Enums\SermonVideoQualityStatus;
use App\Enums\SermonVideoVisibilityOverride;
use App\Models\Builders\SermonBuilder;
use App\Rules\NotEmptyString;
use App\Rules\SermonPointElement;
use App\Sitemap\SermonSitemapPresenter;
use App\Support\SermonProcessingState;
use Database\Factories\SermonFactory;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
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
 * @property SermonPublicationState $publication_state
 * @property ?string $asset_disk
 * @property ?int $historic_import_operation_id
 * @property ?string $audio_file_path
 * @property string $filetype
 * @property string $title
 * @property ?SermonTitleProvenance $title_provenance
 * @property string $slug
 * @property ?string $reference
 * @property string $preacher
 * @property ?int $preacher_id
 * @property ?PreacherSource $preacher_source
 * @property ?float $preacher_confidence
 * @property bool $needs_preacher_review
 * @property ?string $series
 * @property array<int, mixed>|null $points
 * @property ?string $summary
 * @property bool $show_summary
 * @property bool $show_points
 * @property ?string $transcript_file_path
 * @property ?string $thumbnail_file_path
 * @property ?Carbon $thumbnail_generated_at
 * @property ThumbnailMetadata|null $thumbnail_metadata
 * @property ?string $livestream_processing_id
 * @property ?string $video_file_path
 * @property SermonVideoQualityStatus|null $video_quality_status
 * @property ?string $video_quality_reason
 * @property SermonVideoVisibilityOverride|null $video_visibility_override
 * @property ?Carbon $video_quality_assessed_at
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
 * @property-read ?string $plain_thumbnail_file_path
 * @property-read ?string $card_thumbnail_file_path
 * @property-read list<ThumbnailCandidate> $thumbnail_candidates
 * @property-read ThumbnailCandidate|null $selected_thumbnail_candidate
 * @property-read ServiceSection|null $publishedServiceSection
 * @property-read MediaProcessingLog|null $latestProcessingLog
 * @property-read Collection<int, SermonScriptureFilter> $scriptureFilters
 *
 * @method static \Database\Factories\SermonFactory factory(...$parameters)
 * @method static SermonBuilder newModelQuery()
 * @method static SermonBuilder newQuery()
 * @method static SermonBuilder query()
 *
 * @mixin \Eloquent
 */
#[UseEloquentBuilder(SermonBuilder::class)]
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
        'publication_state',
        'asset_disk',
        'historic_import_operation_id',
        'slug',
        'title_provenance',
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
        'video_quality_status',
        'video_quality_reason',
        'video_visibility_override',
        'video_quality_assessed_at',
        'source_type', // Source type: manual, livestream, upload
        'segment_start_time', // Start time of sermon segment in livestream
        'segment_end_time', // End time of sermon segment in livestream
        'duration', // Duration of the sermon in seconds
        'scripture_passage_id',
        'download_count',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'date' => 'date',
            'points' => 'array',
            'service' => SermonService::class,
            'content_type' => SermonContentType::class,
            'publication_state' => SermonPublicationState::class,
            'title_provenance' => SermonTitleProvenance::class,
            'segment_start_time' => 'float',
            'segment_end_time' => 'float',
            'thumbnail_generated_at' => 'datetime',
            'thumbnail_metadata' => ThumbnailMetadataCast::class,
            'show_summary' => 'boolean',
            'show_points' => 'boolean',
            'source_type' => SermonSourceType::class,
            'video_quality_status' => SermonVideoQualityStatus::class,
            'video_visibility_override' => SermonVideoVisibilityOverride::class,
            'video_quality_assessed_at' => 'datetime',
            'preacher_source' => PreacherSource::class,
            'preacher_confidence' => 'float',
            'needs_preacher_review' => 'boolean',
            'download_count' => 'integer',
            'preacher_id' => 'integer',
            'scripture_passage_id' => 'integer',
            'duration' => 'float',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function title(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => trim($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function reference(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * Preacher name attribute.
     *
     * Note: The relationship is named 'preacherProfile' to avoid conflict with
     * this attribute setter which ensures the denormalized name is trimmed.
     *
     * @return Attribute<string, string>
     */
    protected function preacher(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function series(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(?self $sermon = null): array
    {
        $slugRule = ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'];
        $uniqueSlug = Rule::unique('sermons', 'slug');
        if ($sermon) {
            $uniqueSlug->ignore($sermon->id);
        }
        $slugRule[] = $uniqueSlug;

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => $slugRule,
            'date' => ['required', 'date_format:Y-m-d'],
            'audio_file_path' => ['nullable', 'string', 'max:255'],
            'video_file_path' => ['nullable', 'string', 'max:500'],
            'content_type' => ['required', Rule::enum(SermonContentType::class)],
            'source_type' => ['nullable', Rule::enum(SermonSourceType::class)],
            'service' => ['nullable', Rule::enum(SermonService::class)],
            'series' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255', new NotEmptyString],
            'preacher' => ['required', 'string', 'max:255'], // Matches database varchar length and non-empty constraint
            // Security: integer bounding on ID and counter fields adds defence in depth against malformed input and overflow.
            'preacher_id' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807', 'exists:preachers,id'],
            'preacher_source' => ['nullable', Rule::enum(PreacherSource::class)],
            'preacher_confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'segment_start_time' => ['nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'segment_end_time' => ['nullable', 'numeric', 'min:0', 'max:9999999.999', 'gte:segment_start_time'],
            'scripture_passage_id' => ['nullable', 'integer', 'min:1', 'max:9223372036854775807', 'exists:scripture_passages,id'],
            'download_count' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'points' => ['nullable', 'array', 'max:100'],
            'points.*' => ['nullable', new SermonPointElement(255)],
            'points.*.point' => ['nullable', 'string', 'max:255'],
            'points.*.sub_points' => ['nullable', 'array', 'max:50'],
            'points.*.sub_points.*' => ['nullable', 'string', 'max:255'],
            'show_summary' => ['boolean'],
            'show_points' => ['boolean'],
            'needs_preacher_review' => ['boolean'],
            'transcript_file_path' => ['nullable', 'string', 'max:255'],
            'thumbnail_file_path' => ['nullable', 'string', 'max:255'],
            'thumbnail_generated_at' => ['nullable', 'date'],
            'thumbnail_metadata' => ['nullable', 'array'],
            'video_quality_status' => ['nullable', Rule::enum(SermonVideoQualityStatus::class)],
            'video_quality_reason' => ['nullable', 'string', 'max:64'],
            'video_visibility_override' => ['nullable', Rule::enum(SermonVideoVisibilityOverride::class)],
            'video_quality_assessed_at' => ['nullable', 'date'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            'livestream_processing_id' => [
                'nullable',
                'uuid',
                Rule::exists('media_processing_logs', 'processing_id')
                    ->where('processing_type', MediaType::Livestream->value),
            ],
        ];
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function plainThumbnailFilePath(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->thumbnail_metadata?->plainThumbnailPath
        )->shouldCache();
    }

    /**
     * @return Attribute<?string, never>
     */
    protected function cardThumbnailFilePath(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $metadata = $this->thumbnail_metadata;

                if (blank($metadata)) {
                    return null;
                }

                return $metadata->cardThumbnailPath ?? $metadata->plainThumbnailPath;
            }
        )->shouldCache();
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

    /**
     * @return HasMany<SermonScriptureFilter, $this>
     */
    public function scriptureFilters(): HasMany
    {
        return $this->hasMany(SermonScriptureFilter::class);
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
     * Get the processing logs for this sermon.
     *
     * @return HasMany<MediaProcessingLog, $this>
     */
    public function processingLogs(): HasMany
    {
        return $this->hasMany(MediaProcessingLog::class);
    }

    /**
     * Get the livestream processing log for this sermon.
     *
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
        return filled($this->transcript_file_path);
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
        return filled($this->thumbnail_file_path);
    }

    public function hasPlainThumbnail(): bool
    {
        return filled($this->plain_thumbnail_file_path);
    }

    public function hasCardThumbnail(): bool
    {
        return filled($this->card_thumbnail_file_path);
    }

    public function hasThumbnailCandidates(): bool
    {
        return filled($this->thumbnail_candidates);
    }

    public function hasVideoGeneratedThumbnail(): bool
    {
        $metadata = $this->thumbnail_metadata;

        if (blank($metadata)) {
            return false;
        }

        return filled($metadata->videoDuration)
            || filled($metadata->thumbnailCandidates)
            || filled($metadata->selectedThumbnailCandidateId);
    }

    /**
     * @return ThumbnailCandidate|null
     */
    public function findThumbnailCandidate(string $candidateId): ?array
    {
        return $this->thumbnail_metadata?->findCandidate($candidateId);
    }

    /**
     * Check if this sermon was created through automated processing.
     * Logic aligned with SermonBuilder::automated() criteria.
     *
     * @return bool True if sermon was automatically processed
     */
    public function isAutomated(): bool
    {
        if ($this->hasTranscript()) {
            return true;
        }

        if (! $this->exists) {
            return false;
        }

        /**
         * Performance Optimization: Check if relationship is already loaded to prevent N+1 queries.
         * We prioritize latestProcessingLog as it is more commonly eager-loaded in listing views.
         */
        if ($this->relationLoaded('latestProcessingLog')) {
            return filled($this->latestProcessingLog);
        }

        if ($this->relationLoaded('processingLogs')) {
            return $this->processingLogs->isNotEmpty();
        }

        return $this->processingLogs()->exists();
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
     * Read-only view of this sermon's media-processing state.
     *
     * Pipeline state (status, completion, failure, in-progress) describes the
     * related {@see MediaProcessingLog}, not the sermon itself, so it is read
     * through a dedicated collaborator rather than spread across model methods.
     */
    public function processingState(): SermonProcessingState
    {
        return new SermonProcessingState($this->latestProcessingLog);
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
        return filled($this->video_file_path);
    }

    public function videoQualityStatus(): SermonVideoQualityStatus
    {
        return $this->video_quality_status instanceof SermonVideoQualityStatus
            ? $this->video_quality_status
            : SermonVideoQualityStatus::Unassessed;
    }

    public function videoVisibilityOverride(): SermonVideoVisibilityOverride
    {
        return $this->video_visibility_override instanceof SermonVideoVisibilityOverride
            ? $this->video_visibility_override
            : SermonVideoVisibilityOverride::Default;
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
}
