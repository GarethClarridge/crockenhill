<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChurchServiceReviewSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChurchServiceReviewSession extends Model
{
    /** @use HasFactory<ChurchServiceReviewSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'review_uuid',
        'church_service_id',
        'base_canonical_revision',
        'base_canonical_hash',
        'included_proposal_ids',
        'proposal_dispositions',
        'decision_rules',
        'service_field_decisions',
        'manual_source_record_id',
        'resulting_canonical_revision',
        'resulting_canonical_hash',
        'reviewed_by_user_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'included_proposal_ids' => 'array',
            'proposal_dispositions' => 'array',
            'decision_rules' => 'array',
            'service_field_decisions' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChurchService, $this> */
    public function churchService(): BelongsTo
    {
        return $this->belongsTo(ChurchService::class);
    }

    /** @return BelongsTo<ChurchServiceSourceRecord, $this> */
    public function manualSourceRecord(): BelongsTo
    {
        return $this->belongsTo(ChurchServiceSourceRecord::class, 'manual_source_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return HasMany<ChurchServiceReviewDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ChurchServiceReviewDecision::class, 'review_session_id');
    }
}
