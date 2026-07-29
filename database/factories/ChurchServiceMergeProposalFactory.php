<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChurchServiceProposalStatus;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceSourceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChurchServiceMergeProposal> */
class ChurchServiceMergeProposalFactory extends Factory
{
    protected $model = ChurchServiceMergeProposal::class;

    public function definition(): array
    {
        $hash = hash('sha256', $this->faker->unique()->uuid());

        return [
            'church_service_id' => ChurchService::factory(),
            'trigger_source_record_id' => fn (array $attributes): ChurchServiceSourceRecordFactory => ChurchServiceSourceRecord::factory()->state([
                'church_service_id' => $attributes['church_service_id'],
            ]),
            'base_canonical_revision' => 0,
            'included_source_hashes' => [$hash],
            'proposed_items' => [],
            'proposed_hash' => $hash,
            'field_decisions' => [],
            'conflicts' => [],
            'status' => ChurchServiceProposalStatus::Pending,
        ];
    }
}
