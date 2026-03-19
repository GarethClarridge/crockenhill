<?php

namespace Database\Factories;

use App\Models\LivestreamSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

class LivestreamSegmentFactory extends Factory
{
    protected $model = LivestreamSegment::class;

    public function definition(): array
    {
        $startTime = $this->faker->numberBetween(0, 5400); // Up to 1.5 hours
        $duration = $this->faker->numberBetween(60, 1800); // 1 minute to 30 minutes
        $endTime = $startTime + $duration;

        return [
            'media_processing_log_id' => 1, // Will be overridden in tests
            'segment_index' => $this->faker->unique()->numberBetween(0, 255),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $duration,
            'classification' => $this->faker->randomElement(['song', 'speech', 'silence']),
            'avg_rms' => $this->faker->randomFloat(2, 0.1, 1.0),
            'peak_rms' => $this->faker->randomFloat(2, 0.5, 1.0),
            'is_sermon_candidate' => false,
            'segment_order' => $this->faker->numberBetween(1, 10),
            'metadata' => [],
        ];
    }

    public function song(): static
    {
        return $this->state(fn (array $attributes) => [
            'classification' => 'song',
            'is_sermon_candidate' => false,
        ]);
    }

    public function speech(): static
    {
        return $this->state(fn (array $attributes) => [
            'classification' => 'speech',
            'is_sermon_candidate' => false,
        ]);
    }

    public function sermon(): static
    {
        return $this->state(fn (array $attributes) => [
            'classification' => 'speech',
            'is_sermon_candidate' => true,
            'duration' => $this->faker->numberBetween(1200, 3600), // 20-60 minutes
        ]);
    }

    public function forProcessingLog(int $processingLogId): static
    {
        return $this->state(fn (array $attributes) => [
            'media_processing_log_id' => $processingLogId,
        ]);
    }

    /**
     * Set a specific segment index.
     */
    public function withIndex(int $index): static
    {
        return $this->state(fn (array $attributes) => [
            'segment_index' => $index,
        ]);
    }
}
