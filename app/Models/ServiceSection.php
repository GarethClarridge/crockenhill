<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

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
 * @property array<string, mixed>|null $metadata
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
            'metadata' => 'array',
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

    public function isPublishableType(): bool
    {
        $publishableTypes = config('media-processing.section_publishing.publishable_types', ['childrens_talk']);
        if (! is_array($publishableTypes)) {
            return false;
        }

        return in_array($this->section_type->value, $publishableTypes, true);
    }

    public function canTransitionTo(ServiceSectionPublicationStatus $target): bool
    {
        $current = $this->publication_status;
        $allowed = match ($current) {
            ServiceSectionPublicationStatus::NOT_APPLICABLE => [
                ServiceSectionPublicationStatus::PENDING_APPROVAL,
            ],
            ServiceSectionPublicationStatus::PENDING_APPROVAL => [
                ServiceSectionPublicationStatus::APPROVED,
                ServiceSectionPublicationStatus::REJECTED,
                ServiceSectionPublicationStatus::NOT_APPLICABLE,
            ],
            ServiceSectionPublicationStatus::APPROVED => [
                ServiceSectionPublicationStatus::PUBLISHED,
                ServiceSectionPublicationStatus::REJECTED,
                ServiceSectionPublicationStatus::NOT_APPLICABLE,
            ],
            ServiceSectionPublicationStatus::REJECTED => [
                ServiceSectionPublicationStatus::PENDING_APPROVAL,
                ServiceSectionPublicationStatus::NOT_APPLICABLE,
            ],
            ServiceSectionPublicationStatus::PUBLISHED => [
                ServiceSectionPublicationStatus::PENDING_APPROVAL,
                ServiceSectionPublicationStatus::NOT_APPLICABLE,
            ],
        };

        if ($target === $current) {
            return true;
        }

        return in_array($target, $allowed, true);
    }

    public function transitionTo(ServiceSectionPublicationStatus $target): bool
    {
        if (! $this->canTransitionTo($target)) {
            Log::error('Invalid service section publication transition attempted', [
                'service_section_id' => $this->id,
                'from' => $this->publication_status->value,
                'to' => $target->value,
            ]);

            return false;
        }

        $this->publication_status = $target;

        return true;
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
        return [
            'church_service_item_id' => $this->church_service_item_id,
            'section_type' => $this->section_type->value,
            'title' => $this->title,
            'start_time' => (float) $this->start_time,
            'end_time' => (float) $this->end_time,
        ];
    }

    public function classificationSignature(): string
    {
        return hash('sha256', (string) json_encode($this->classificationSignaturePayload()));
    }
}
