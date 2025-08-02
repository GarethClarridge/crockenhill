<?php

namespace Database\Factories;

use App\Models\LivestreamProcessingLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class LivestreamProcessingLogFactory extends Factory
{
    protected $model = LivestreamProcessingLog::class;

    public function definition(): array
    {
        return [
            'processing_id' => Str::uuid()->toString(),
            'original_filename' => $this->faker->randomElement(['service-2024-01-15.mp4', 'morning-service.mp4', 'evening-service.mp4']),
            'original_file_path' => 'livestreams/' . Str::uuid() . '.mp4',
            'file_size' => $this->faker->numberBetween(100000000, 2000000000), // 100MB to 2GB
            'file_format' => 'mp4',
            'duration' => $this->faker->numberBetween(1800, 7200), // 30 minutes to 2 hours
            'status' => $this->faker->randomElement(['pending', 'processing', 'segmenting', 'extraction_complete', 'sermon_submitted', 'completed', 'failed']),
            'error_message' => null,
            'sermon_id' => null,
            'created_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
            'updated_at' => function (array $attributes) {
                return $this->faker->dateTimeBetween($attributes['created_at'], 'now');
            },
            'completed_at' => function (array $attributes) {
                return $attributes['status'] === 'completed' ? 
                    $this->faker->dateTimeBetween($attributes['created_at'], 'now') : 
                    null;
            },
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => $this->faker->dateTimeBetween($attributes['created_at'] ?? '-1 week', 'now'),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => $this->faker->randomElement([
                'FFmpeg processing failed',
                'No sermon segments found', 
                'File corruption detected',
                'Insufficient storage space'
            ]),
            'completed_at' => $this->faker->dateTimeBetween($attributes['created_at'] ?? '-1 week', 'now'),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'completed_at' => null,
        ]);
    }

    public function withSermon(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'sermon_id' => $this->faker->numberBetween(1, 100),
            'completed_at' => $this->faker->dateTimeBetween($attributes['created_at'] ?? '-1 week', 'now'),
        ]);
    }
}