<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChurchServiceProposalClassReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One census class's recorded standing in the review-load loop.
 *
 * The §9.4.6 gate is not "review load is small enough"; it is that every class is
 * accounted for. A class is either `automated` — the projector was changed so the
 * class stops arising — or `irreducible`, meaning a human decides each member and
 * the cost of doing so is measured rather than assumed.
 *
 * @property string $status
 * @property string $reason
 * @property int|null $seconds_per_decision
 */
class ChurchServiceProposalClassReview extends Model
{
    /** @use HasFactory<ChurchServiceProposalClassReviewFactory> */
    use HasFactory;

    public const UNCLASSIFIED = 'unclassified';

    public const AUTOMATED = 'automated';

    public const IRREDUCIBLE = 'irreducible';

    /** @var list<string> */
    public const STATUSES = [self::AUTOMATED, self::IRREDUCIBLE];

    protected $fillable = [
        'class_key',
        'status',
        'reason',
        'seconds_per_decision',
        'marked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'seconds_per_decision' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }
}
