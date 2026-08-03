<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChurchServiceProposalStatus;
use App\Models\ChurchServiceProposalDecisionRule;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChurchServiceProposalDecisionRule> */
class ChurchServiceProposalDecisionRuleFactory extends Factory
{
    protected $model = ChurchServiceProposalDecisionRule::class;

    public function definition(): array
    {
        return [
            'class_key' => CanonicalJson::hash([
                'match_tier' => 2,
                'conflict_kinds' => ['ambiguous_repeat_match'],
                'subject' => 'custom:example',
            ]),
            'match_tier' => 2,
            'disposition' => ChurchServiceProposalStatus::Accepted->value,
            'proposal_identities' => [],
            'rationale' => 'Approved proposal class rule.',
            'reviewed_by_user_id' => User::factory(),
            'applied_at' => now(),
        ];
    }
}
