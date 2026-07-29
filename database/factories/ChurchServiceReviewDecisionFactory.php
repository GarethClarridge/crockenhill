<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChurchServiceReviewDecision;
use App\Models\ChurchServiceReviewSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChurchServiceReviewDecision> */
class ChurchServiceReviewDecisionFactory extends Factory
{
    protected $model = ChurchServiceReviewDecision::class;

    public function definition(): array
    {
        return [
            'review_session_id' => ChurchServiceReviewSession::factory(),
            'included' => true,
            'final_position' => 1,
        ];
    }
}
