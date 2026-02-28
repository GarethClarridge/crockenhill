<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property ServiceSectionStatus $status
 * @property bool $needs_manual_review
 * @property array<int, int> $source_segment_ids
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ChurchServiceItem|null $churchServiceItem
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
        'status',
        'needs_manual_review',
        'source_segment_ids',
        'metadata',
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
            'status' => ServiceSectionStatus::class,
            'needs_manual_review' => 'boolean',
            'source_segment_ids' => 'array',
            'metadata' => 'array',
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
}
