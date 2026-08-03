<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChurchServiceProposalClassReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChurchServiceProposalClassReview> */
class ChurchServiceProposalClassReviewFactory extends Factory
{
    protected $model = ChurchServiceProposalClassReview::class;

    public function definition(): array
    {
        return [
            'class_key' => CanonicalJson::hash(['class' => $this->faker->unique()->word()]),
            'status' => ChurchServiceProposalClassReview::AUTOMATED,
            'reason' => 'A tier change settled this class for every future service.',
            'seconds_per_decision' => null,
            'marked_by_user_id' => User::factory(),
        ];
    }

    public function irreducible(int $secondsPerDecision = 30): self
    {
        return $this->state(fn (): array => [
            'status' => ChurchServiceProposalClassReview::IRREDUCIBLE,
            'reason' => 'The sources genuinely disagree about order; no tier change settles it.',
            'seconds_per_decision' => $secondsPerDecision,
        ]);
    }
}
