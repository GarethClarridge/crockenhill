<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChurchServiceOccurrenceState;
use Database\Factories\ChurchServiceReviewDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchServiceReviewDecision extends Model
{
    /** @use HasFactory<ChurchServiceReviewDecisionFactory> */
    use HasFactory;

    protected $fillable = [
        'review_session_id',
        'selected_assertion_id',
        'included',
        'final_position',
        'custom_value',
        'song_id',
        'song_canonical_key',
        'scripture_reference',
        'occurrence_decision',
        'rationale',
    ];

    protected function casts(): array
    {
        return [
            'included' => 'boolean',
            'custom_value' => 'array',
            'occurrence_decision' => ChurchServiceOccurrenceState::class,
        ];
    }

    /** @return BelongsTo<ChurchServiceReviewSession, $this> */
    public function reviewSession(): BelongsTo
    {
        return $this->belongsTo(ChurchServiceReviewSession::class, 'review_session_id');
    }

    /** @return BelongsTo<ChurchServiceItemAssertion, $this> */
    public function selectedAssertion(): BelongsTo
    {
        return $this->belongsTo(ChurchServiceItemAssertion::class, 'selected_assertion_id');
    }

    /** @return BelongsTo<Song, $this> */
    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class);
    }
}
