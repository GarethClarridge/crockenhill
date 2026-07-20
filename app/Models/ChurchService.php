<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ChurchServiceImportMetadata;
use App\Data\ChurchServiceImportMetadataCast;
use App\Enums\ChurchServiceCanonicalConflictReason;
use App\Enums\ChurchServiceCanonicalConflictState;
use App\Enums\ChurchServiceReviewState;
use App\Enums\SermonService;
use Database\Factories\ChurchServiceFactory;
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
 * @property Carbon $date
 * @property SermonService $service
 * @property string $source
 * @property string|null $original_filename
 * @property bool $needs_review
 * @property ChurchServiceReviewState $review_state
 * @property Carbon|null $manual_reviewed_at
 * @property int|null $manual_reviewed_by_user_id
 * @property Carbon|null $manual_review_reopened_at
 * @property string|null $manual_review_reopened_by_source
 * @property ChurchServiceCanonicalConflictState $canonical_conflict_state
 * @property Carbon|null $canonical_conflict_detected_at
 * @property string|null $canonical_conflict_incoming_source
 * @property bool|null $canonical_conflict_reviewed_previously
 * @property bool|null $canonical_conflict_canonical_changed
 * @property ChurchServiceCanonicalConflictReason|null $canonical_conflict_reason
 * @property ChurchServiceImportMetadata|null $import_metadata
 * @property string|null $pending_structure_merge_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ChurchServiceItem> $items
 * @property-read Collection<int, MediaProcessingLog> $mediaProcessingLogs
 * @property-read User|null $manualReviewedBy
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
    /** @use HasFactory<ChurchServiceFactory> */
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
        'pending_structure_merge_source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
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
     * Eager load service items in the order used by the admin workbench.
     *
     * @param  Builder<ChurchService>  $query
     * @return Builder<ChurchService>
     */
    public function scopeWithOrderedItems(Builder $query, bool $withSong = false): Builder
    {
        return $query->with([
            /** @param HasMany<ChurchServiceItem, ChurchService> $itemsQuery */
            'items' => function ($itemsQuery) use ($withSong): void {
                if ($withSong) {
                    $itemsQuery->with('song:id,title');
                }

                $itemsQuery->orderBy('position')->orderBy('id');
            },
        ]);
    }

    /**
     * @return HasMany<MediaProcessingLog, $this>
     */
    public function mediaProcessingLogs(): HasMany
    {
        return $this->hasMany(MediaProcessingLog::class);
    }

    /**
     * @return array<string, list<string|mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'date' => ['required', 'date'],
            'service' => ['required', Rule::enum(SermonService::class)],
            'source' => ['required', 'string', 'max:255'],
            'original_filename' => ['nullable', 'string', 'max:255'],
            'needs_review' => ['boolean'],
            'review_state' => ['required', Rule::enum(ChurchServiceReviewState::class)],
            'manual_reviewed_by_user_id' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'exists:users,id'],
            'canonical_conflict_state' => ['required', Rule::enum(ChurchServiceCanonicalConflictState::class)],
            'canonical_conflict_reason' => ['nullable', Rule::enum(ChurchServiceCanonicalConflictReason::class)],
            'pending_structure_merge_source' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manualReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_reviewed_by_user_id');
    }
}
