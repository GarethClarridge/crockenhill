<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ChurchServiceImportMetadata;
use App\Data\ChurchServiceImportMetadataCast;
use App\Enums\ChurchServiceCanonicalFinalization;
use App\Enums\ChurchServiceReviewState;
use App\Enums\SermonService;
use App\Services\Public\PublicServiceContentEligibility;
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
 * @property string|null $review_reason
 * @property string|null $summary
 * @property list<array{title: string, details: string|null}>|null $notices
 * @property list<array{title: string, start_time: float, end_time: float}>|null $chapter_markers
 * @property ChurchServiceReviewState $review_state
 * @property Carbon|null $manual_reviewed_at
 * @property int|null $manual_reviewed_by_user_id
 * @property Carbon|null $manual_review_reopened_at
 * @property string|null $manual_review_reopened_by_source
 * @property ChurchServiceImportMetadata|null $import_metadata
 * @property string|null $pending_structure_merge_source
 * @property int $canonical_revision
 * @property string|null $canonical_hash
 * @property int|null $reviewed_canonical_revision
 * @property ChurchServiceCanonicalFinalization|null $canonical_finalization
 * @property int|null $projection_policy_version
 * @property string|null $source_summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ChurchServiceItem> $items
 * @property-read Collection<int, MediaProcessingLog> $mediaProcessingLogs
 * @property-read Collection<int, ChurchServiceSourceRecord> $sourceRecords
 * @property-read Collection<int, ChurchServiceMergeProposal> $mergeProposals
 * @property-read Collection<int, ChurchServiceReviewSession> $reviewSessions
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

    protected $attributes = [
        'canonical_revision' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'date',
        'service',
        'source',
        'original_filename',
        'needs_review',
        'review_reason',
        'summary',
        'notices',
        'chapter_markers',
        'review_state',
        'manual_reviewed_at',
        'manual_reviewed_by_user_id',
        'manual_review_reopened_at',
        'manual_review_reopened_by_source',
        'import_metadata',
        'pending_structure_merge_source',
        'canonical_revision',
        'canonical_hash',
        'reviewed_canonical_revision',
        'canonical_finalization',
        'projection_policy_version',
        'source_summary',
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
            'notices' => 'array',
            'chapter_markers' => 'array',
            'review_state' => ChurchServiceReviewState::class,
            'manual_reviewed_at' => 'datetime',
            'manual_reviewed_by_user_id' => 'integer',
            'manual_review_reopened_at' => 'datetime',
            'import_metadata' => ChurchServiceImportMetadataCast::class,
            'canonical_revision' => 'integer',
            'reviewed_canonical_revision' => 'integer',
            'canonical_finalization' => ChurchServiceCanonicalFinalization::class,
            'projection_policy_version' => 'integer',
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
     * Restrict a query to the current era — the services the weekly attention inbox is about.
     *
     * The historic import deliberately creates many services flagged `needs_review`: REV-D2
     * imports identity-corroborated email evidence unattended and leaves it unreviewed, which is
     * the point. Those belong to the per-round proposal census, not to the queue an operator
     * checks each week, and the incremental-convergence plan §9 says so explicitly: "Evidence-tier
     * imports (IC1) enter the census, not the weekly attention inbox. The weekly inbox keeps its
     * narrower `needs_review` semantics on purpose." Without this the members-home badge would
     * count several hundred historic services and stop meaning anything.
     *
     * `church.services.public_from` is the era boundary already used by every public read path,
     * so the two agree by construction. Unlike those paths this one fails *open*: an unset or
     * malformed boundary hides nothing, because silently dropping work out of the operator's own
     * queue is the worse failure here.
     *
     * @param  Builder<ChurchService>  $query
     * @return Builder<ChurchService>
     */
    public function scopeInCurrentEra(Builder $query): Builder
    {
        $publicFrom = app(PublicServiceContentEligibility::class)->publicFrom();

        if (! $publicFrom instanceof Carbon) {
            return $query;
        }

        return $query->whereDate('church_services.date', '>=', $publicFrom->toDateString());
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

    /** @return HasMany<ChurchServiceSourceRecord, $this> */
    public function sourceRecords(): HasMany
    {
        return $this->hasMany(ChurchServiceSourceRecord::class);
    }

    /** @return HasMany<ChurchServiceMergeProposal, $this> */
    public function mergeProposals(): HasMany
    {
        return $this->hasMany(ChurchServiceMergeProposal::class);
    }

    /** @return HasMany<ChurchServiceReviewSession, $this> */
    public function reviewSessions(): HasMany
    {
        return $this->hasMany(ChurchServiceReviewSession::class);
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
            'review_reason' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'notices' => ['nullable', 'array'],
            'chapter_markers' => ['nullable', 'array'],
            'review_state' => ['required', Rule::enum(ChurchServiceReviewState::class)],
            'canonical_finalization' => ['nullable', Rule::enum(ChurchServiceCanonicalFinalization::class)],
            'projection_policy_version' => ['nullable', 'integer', 'min:1'],
            'manual_reviewed_by_user_id' => ['nullable', 'integer', 'min:1', 'max:4294967295', 'exists:users,id'],
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
