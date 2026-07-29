<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChurchServiceEvidenceKind;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceSourceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChurchServiceItemAssertion> */
class ChurchServiceItemAssertionFactory extends Factory
{
    protected $model = ChurchServiceItemAssertion::class;

    public function definition(): array
    {
        return [
            'source_record_id' => ChurchServiceSourceRecord::factory(),
            'assertion_key' => $this->faker->uuid(),
            'source_position' => 1,
            'evidence_kind' => ChurchServiceEvidenceKind::Planned,
            'type' => 'custom',
            'title' => $this->faker->sentence(3),
        ];
    }
}
