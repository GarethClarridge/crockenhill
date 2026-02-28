<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SermonService;
use App\Models\ChurchService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChurchService>
 */
class ChurchServiceFactory extends Factory
{
    protected $model = ChurchService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => $this->faker->date(),
            'service' => $this->faker->randomElement([
                SermonService::MORNING->value,
                SermonService::EVENING->value,
                SermonService::OTHER->value,
            ]),
            'source' => 'openlp',
            'original_filename' => $this->faker->date('Y-m-d').' AM.osz',
            'needs_review' => false,
            'import_metadata' => [
                'confidence_score' => 1.0,
                'warnings' => [],
            ],
        ];
    }
}
