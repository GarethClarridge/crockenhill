<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ChildrensTalkSpeakerMetadata;
use App\Data\ServiceSectionMetadata;
use App\Data\ServiceSectionMetadataCast;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Support\MediaAssetPath;
use Database\Factories\ServiceSectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * @property int $id
 * @property int $media_processing_log_id
 * @property int|null $church_service_item_id
 * @property ServiceSectionType $section_type
 * @property int $section_order
 * @property string|null $title
 * @property string|null $summary
 * @property float $start_time
 * @property float $end_time
 * @property float $duration
 * @property float|null $confidence
 * @property ServiceSectionStatus $status
 * @property bool $needs_manual_review
 * @property array<int, int> $source_segment_ids
 * @property ServiceSectionMetadata|null $metadata
 * @property ServiceSectionSongMatchType|null $song_match_type
 * @property int|null $matched_item_id
 * @property int|null $expected_item_id
 * @property ServiceSectionPublicationStatus $publication_status
 * @property int|null $published_sermon_id
 * @property string|null $extracted_video_path
 * @property string|null $extracted_audio_path
 * @property string|null $asset_disk
 * @property Carbon|null $published_at
 * @property Carbon|null $extracted_at
 * @property Carbon|null $unpublished_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChurchServiceItem|null $churchServiceItem
 * @property-read Sermon|null $publishedSermon
 * @property-read Collection<int, SongVideo> $songVideos
 * @property-read MediaProcessingLog $processingLog
 *
 * @method static \Database\Factories\ServiceSectionFactory factory(...$parameters)
 * @method static Builder<ServiceSection> newModelQuery()
 * @method static Builder<ServiceSection> newQuery()
 * @method static Builder<ServiceSection> query()
 *
 * @mixin \Eloquent
 */
class ServiceSection extends Model
{
    /** @use HasFactory<ServiceSectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'media_processing_log_id',
        'church_service_item_id',
        'section_type',
        'section_order',
        'title',
        'summary',
        'start_time',
        'end_time',
        'duration',
        'confidence',
        'status',
        'needs_manual_review',
        'source_segment_ids',
        'metadata',
        'song_match_type',
        'matched_item_id',
        'expected_item_id',
        'publication_status',
        'published_sermon_id',
        'published_at',
        'extracted_video_path',
        'extracted_audio_path',
        'asset_disk',
        'extracted_at',
        'unpublished_expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'media_processing_log_id' => 'integer',
            'church_service_item_id' => 'integer',
            'section_type' => ServiceSectionType::class,
            'section_order' => 'integer',
            'start_time' => 'float',
            'end_time' => 'float',
            'duration' => 'float',
            'confidence' => 'float',
            'status' => ServiceSectionStatus::class,
            'needs_manual_review' => 'boolean',
            'source_segment_ids' => 'array',
            'metadata' => ServiceSectionMetadataCast::class,
            'song_match_type' => ServiceSectionSongMatchType::class,
            'matched_item_id' => 'integer',
            'expected_item_id' => 'integer',
            'publication_status' => ServiceSectionPublicationStatus::class,
            'published_sermon_id' => 'integer',
            'published_at' => 'datetime',
            'extracted_at' => 'datetime',
            'unpublished_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MediaProcessingLog, $this>
     */
    public function processingLog(): BelongsTo
    {
        return $this->belongsTo(MediaProcessingLog::class, 'media_processing_log_id');
    }

    /**
     * @return BelongsTo<ChurchServiceItem, $this>
     */
    public function churchServiceItem(): BelongsTo
    {
        return $this->belongsTo(ChurchServiceItem::class, 'church_service_item_id')
            ->withTrashed();
    }

    /**
     * @return BelongsTo<Sermon, $this>
     */
    public function publishedSermon(): BelongsTo
    {
        return $this->belongsTo(Sermon::class, 'published_sermon_id');
    }

    /**
     * @return HasMany<SongVideo, $this>
     */
    public function songVideos(): HasMany
    {
        return $this->hasMany(SongVideo::class, 'service_section_id');
    }

    /**
     * Exclude sections belonging to a superseded processing run (a duplicate or
     * earlier processing of the same service). They are kept for audit but never
     * count toward review — the winning run owns the service's structure. Mirrors
     * the exclusion in ServiceReviewDashboardQuery's review-candidate predicate so
     * the read and reconcile sides share one definition.
     *
     * @param  Builder<ServiceSection>  $query
     * @return Builder<ServiceSection>
     */
    public function scopeNotSuperseded(Builder $query): Builder
    {
        return $query->whereDoesntHave('processingLog', function (Builder $log): void {
            $log->whereNotNull('superseded_at');
        });
    }

    /**
     * Scope to the sections belonging to a service. A section belongs through its
     * processing log or its projected item; either path counts.
     *
     * @param  Builder<ServiceSection>  $query
     * @return Builder<ServiceSection>
     */
    public function scopeForService(Builder $query, ChurchService|int $service): Builder
    {
        $serviceId = $service instanceof ChurchService ? $service->id : $service;

        return $query->where(function (Builder $query) use ($serviceId): void {
            $query->whereHas('processingLog', fn (Builder $log): Builder => $log->where('church_service_id', $serviceId))
                ->orWhereHas('churchServiceItem', fn (Builder $item): Builder => $item->where('church_service_id', $serviceId));
        });
    }

    /**
     * @return array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float
     * }
     */
    public function classificationSignaturePayload(): array
    {
        $payload = [
            'church_service_item_id' => $this->church_service_item_id,
            'section_type' => $this->section_type->value,
            'title' => $this->title,
            'start_time' => (float) $this->start_time,
            'end_time' => (float) $this->end_time,
        ];

        if ($this->section_type === ServiceSectionType::ChildrensTalk) {
            $payload['publication_speaker'] = $this->publicationSpeakerSignaturePayload();
        }

        return $payload;
    }

    public function classificationSignature(): string
    {
        return hash('sha256', (string) json_encode($this->classificationSignaturePayload()));
    }

    /**
     * The disk holding this section's extracted candidate media.
     *
     * A promoted historic candidate records its own disk, because the globally
     * configured media disk is rebound during a historic batch and moves on
     * afterwards -- so resolving through configuration would look for quarantined
     * bytes wherever the *current* run happens to point. Rows with no recorded
     * disk keep resolving through configuration exactly as before.
     */
    public function extractedAssetDisk(): string
    {
        return filled($this->asset_disk) ? (string) $this->asset_disk : MediaAssetPath::disk();
    }

    public function hasConfirmedSongMatch(): bool
    {
        return $this->song_match_type === ServiceSectionSongMatchType::Confirmed;
    }

    public function hasInferredSongMatch(): bool
    {
        return $this->song_match_type === ServiceSectionSongMatchType::Inferred;
    }

    public function hasUnmatchedSongMatch(): bool
    {
        return $this->song_match_type === ServiceSectionSongMatchType::Unmatched;
    }

    /**
     * @return array<string, mixed>
     */
    public function childrensTalkSpeakerMetadata(): array
    {
        $speaker = $this->metadata?->childrensTalkSpeaker;

        return $speaker instanceof ChildrensTalkSpeakerMetadata ? $speaker->toArray() : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function predictedChildrensTalkSpeaker(): ?array
    {
        return $this->metadata?->childrensTalkSpeaker?->predicted;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reviewedChildrensTalkSpeaker(): ?array
    {
        return $this->metadata?->childrensTalkSpeaker?->reviewed;
    }

    /**
     * @return array{preacher_id:int|null,preacher_name:string,source:string,confidence:float|null}|null
     */
    public function publicationChildrensTalkSpeaker(): ?array
    {
        return $this->metadata?->childrensTalkSpeaker?->publicationSpeaker();
    }

    public function hasResolvedChildrensTalkSpeaker(): bool
    {
        return $this->section_type !== ServiceSectionType::ChildrensTalk
            || $this->publicationChildrensTalkSpeaker() !== null;
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'media_processing_log_id' => ['required', 'integer', 'min:1', 'exists:media_processing_logs,id'],
            'church_service_item_id' => ['nullable', 'integer', 'min:1', 'exists:church_service_items,id'],
            'section_type' => ['required', Rule::enum(ServiceSectionType::class)],
            'section_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'title' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'start_time' => ['required', 'numeric', 'min:0', 'max:9999999.999'],
            'end_time' => ['required', 'numeric', 'min:0', 'max:9999999.999', 'gt:start_time'],
            'duration' => ['required', 'numeric', 'min:0', 'max:9999999.999'],
            'status' => ['required', Rule::enum(ServiceSectionStatus::class)],
            'needs_manual_review' => ['boolean'],
            'source_segment_ids' => ['required', 'array'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'song_match_type' => ['nullable', Rule::enum(ServiceSectionSongMatchType::class)],
            'matched_item_id' => ['nullable', 'integer', 'min:1', 'exists:church_service_items,id'],
            'expected_item_id' => ['nullable', 'integer', 'min:1', 'exists:church_service_items,id'],
            'publication_status' => ['required', Rule::enum(ServiceSectionPublicationStatus::class)],
            'published_sermon_id' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'exists:sermons,id'],
            'extracted_video_path' => ['nullable', 'string', 'max:255'],
            'extracted_audio_path' => ['nullable', 'string', 'max:255'],
            'asset_disk' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{preacher_id:int|null,preacher_name:string,source:string}|null
     */
    private function publicationSpeakerSignaturePayload(): ?array
    {
        $speaker = $this->publicationChildrensTalkSpeaker();
        if ($speaker === null) {
            return null;
        }

        return [
            'preacher_id' => $speaker['preacher_id'],
            'preacher_name' => $speaker['preacher_name'],
            'source' => $speaker['source'],
        ];
    }
}
