<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChurchService;
use App\Models\ChurchServiceReviewSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChurchServiceReviewSession> */
class ChurchServiceReviewSessionFactory extends Factory
{
    protected $model = ChurchServiceReviewSession::class;

    public function definition(): array
    {
        return [
            'review_uuid' => $this->faker->uuid(),
            'church_service_id' => ChurchService::factory(),
            'base_canonical_revision' => 0,
            'included_proposal_ids' => [],
            'proposal_dispositions' => [],
            'decision_rules' => [],
            'service_field_decisions' => [],
            'reviewed_by_user_id' => User::factory(),
        ];
    }
}
