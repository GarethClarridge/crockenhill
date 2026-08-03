<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChurchServiceProposalDecisionRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property list<string> $proposal_identities
 */
class ChurchServiceProposalDecisionRule extends Model
{
    /** @use HasFactory<ChurchServiceProposalDecisionRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'class_key',
        'match_tier',
        'disposition',
        'proposal_identities',
        'rationale',
        'reviewed_by_user_id',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'match_tier' => 'integer',
            'proposal_identities' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return HasMany<ChurchServiceMergeProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(ChurchServiceMergeProposal::class, 'decision_rule_id');
    }
}
