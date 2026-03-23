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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $media_processing_log_id
 * @property int|null $church_service_item_id
 * @property ServiceSectionType $section_type
 * @property int $section_order
 * @property string|null $title
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
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $extracted_at
 * @property \Illuminate\Support\Carbon|null $unpublished_expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ChurchServiceItem|null $churchServiceItem
 * @property-read Sermon|null $publishedSermon
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
    /** @use HasFactory<\Database\Factories\ServiceSectionFactory> */
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
        'extracted_at',
        'unpublished_expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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

        if ($this->section_type === ServiceSectionType::CHILDRENS_TALK) {
            $payload['publication_speaker'] = $this->publicationSpeakerSignaturePayload();
        }

        return $payload;
    }

    public function classificationSignature(): string
    {
        return hash('sha256', (string) json_encode($this->classificationSignaturePayload()));
    }

    /**
     * Check whether both extracted video and audio files are present on the sermon disk.
     */
    public function hasExtractedMedia(): bool
    {
        $videoPath = $this->extracted_video_path;
        $audioPath = $this->extracted_audio_path;

        if (! is_string($videoPath) || $videoPath === '' || ! is_string($audioPath) || $audioPath === '') {
            return false;
        }

        return Storage::disk($this->extractedAssetDisk($videoPath))->exists($videoPath)
            && Storage::disk($this->extractedAssetDisk($audioPath))->exists($audioPath);
    }

    public function extractedAssetDisk(?string $path): string
    {
        return MediaAssetPath::diskForPath($path);
    }

    public function songMatchType(): ?ServiceSectionSongMatchType
    {
        if ($this->song_match_type instanceof ServiceSectionSongMatchType) {
            return $this->song_match_type;
        }

        $matchType = $this->metadataData()->oosAlignment?->songMatchType;

        return is_string($matchType)
            ? ServiceSectionSongMatchType::tryFrom($matchType)
            : null;
    }

    public function matchedItemId(): ?int
    {
        return is_int($this->matched_item_id)
            ? $this->matched_item_id
            : $this->metadataData()->oosAlignment?->matchedItemId;
    }

    public function expectedItemId(): ?int
    {
        return is_int($this->expected_item_id)
            ? $this->expected_item_id
            : $this->metadataData()->oosAlignment?->expectedItemId;
    }

    public function hasConfirmedSongMatch(): bool
    {
        return $this->songMatchType() === ServiceSectionSongMatchType::CONFIRMED;
    }

    public function hasInferredSongMatch(): bool
    {
        return $this->songMatchType() === ServiceSectionSongMatchType::INFERRED;
    }

    public function hasUnmatchedSongMatch(): bool
    {
        return $this->songMatchType() === ServiceSectionSongMatchType::UNMATCHED;
    }

    /**
     * @return array<string, mixed>
     */
    public function childrensTalkSpeakerMetadata(): array
    {
        $speaker = $this->metadataData()->childrensTalkSpeaker;

        return $speaker instanceof ChildrensTalkSpeakerMetadata ? $speaker->toArray() : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function predictedChildrensTalkSpeaker(): ?array
    {
        return $this->metadataData()->childrensTalkSpeaker?->predicted;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reviewedChildrensTalkSpeaker(): ?array
    {
        return $this->metadataData()->childrensTalkSpeaker?->reviewed;
    }

    /**
     * @return array{preacher_id:int|null,preacher_name:string,source:string,confidence:float|null}|null
     */
    public function publicationChildrensTalkSpeaker(): ?array
    {
        return $this->metadataData()->childrensTalkSpeaker?->publicationSpeaker();
    }

    public function hasResolvedChildrensTalkSpeaker(): bool
    {
        return $this->section_type !== ServiceSectionType::CHILDRENS_TALK
            || $this->publicationChildrensTalkSpeaker() !== null;
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

    public function metadataData(): ServiceSectionMetadata
    {
        $metadata = $this->metadata;

        return $metadata instanceof ServiceSectionMetadata
            ? $metadata
            : ServiceSectionMetadata::fromArray($metadata);
    }
}
