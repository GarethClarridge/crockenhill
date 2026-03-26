<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ChurchServiceImportMetadata;
use App\Data\ChurchServiceImportMetadataCast;
use App\Enums\ChurchServiceCanonicalConflictReason;
use App\Enums\ChurchServiceCanonicalConflictState;
use App\Enums\ChurchServiceReviewState;
use App\Enums\SermonService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property \Illuminate\Support\Carbon $date
 * @property SermonService $service
 * @property string $source
 * @property string|null $original_filename
 * @property bool $needs_review
 * @property ChurchServiceReviewState $review_state
 * @property \Illuminate\Support\Carbon|null $manual_reviewed_at
 * @property int|null $manual_reviewed_by_user_id
 * @property \Illuminate\Support\Carbon|null $manual_review_reopened_at
 * @property string|null $manual_review_reopened_by_source
 * @property ChurchServiceCanonicalConflictState $canonical_conflict_state
 * @property \Illuminate\Support\Carbon|null $canonical_conflict_detected_at
 * @property string|null $canonical_conflict_incoming_source
 * @property bool|null $canonical_conflict_reviewed_previously
 * @property bool|null $canonical_conflict_canonical_changed
 * @property ChurchServiceCanonicalConflictReason|null $canonical_conflict_reason
 * @property ChurchServiceImportMetadata|null $import_metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ChurchServiceItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MediaProcessingLog> $mediaProcessingLogs
 *
 * @method static \Database\Factories\ChurchServiceFactory factory(...$parameters)
 * @method static Builder<ChurchService> newModelQuery()
 * @method static Builder<ChurchService> newQuery()
 * @method static Builder<ChurchService> query()
 *
 * @mixin \Eloquent
 */
class ChurchService extends Model
{
    /** @use HasFactory<\Database\Factories\ChurchServiceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'service',
        'source',
        'original_filename',
        'needs_review',
        'review_state',
        'manual_reviewed_at',
        'manual_reviewed_by_user_id',
        'manual_review_reopened_at',
        'manual_review_reopened_by_source',
        'canonical_conflict_state',
        'canonical_conflict_detected_at',
        'canonical_conflict_incoming_source',
        'canonical_conflict_reviewed_previously',
        'canonical_conflict_canonical_changed',
        'canonical_conflict_reason',
        'import_metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'service' => SermonService::class,
            'needs_review' => 'boolean',
            'review_state' => ChurchServiceReviewState::class,
            'manual_reviewed_at' => 'datetime',
            'manual_reviewed_by_user_id' => 'integer',
            'manual_review_reopened_at' => 'datetime',
            'canonical_conflict_state' => ChurchServiceCanonicalConflictState::class,
            'canonical_conflict_detected_at' => 'datetime',
            'canonical_conflict_reviewed_previously' => 'boolean',
            'canonical_conflict_canonical_changed' => 'boolean',
            'canonical_conflict_reason' => ChurchServiceCanonicalConflictReason::class,
            'import_metadata' => ChurchServiceImportMetadataCast::class,
        ];
    }

    /**
     * @return HasMany<ChurchServiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChurchServiceItem::class);
    }

    /**
     * @return HasMany<MediaProcessingLog, $this>
     */
    public function mediaProcessingLogs(): HasMany
    {
        return $this->hasMany(MediaProcessingLog::class);
    }
}
