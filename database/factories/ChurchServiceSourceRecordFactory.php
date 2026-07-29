<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChurchServiceSourceRecord> */
class ChurchServiceSourceRecordFactory extends Factory
{
    protected $model = ChurchServiceSourceRecord::class;

    public function definition(): array
    {
        return [
            'church_service_id' => ChurchService::factory(),
            'source' => ChurchServiceSource::Email,
            'source_key' => $this->faker->uuid(),
            'revision_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'input_hash' => hash('sha256', $this->faker->unique()->uuid()),
            'processing_fingerprint' => ['version' => 1],
            'payload_complete' => true,
            'captured_at' => now(),
        ];
    }
}
