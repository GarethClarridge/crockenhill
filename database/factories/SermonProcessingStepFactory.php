<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SermonProcessingStep>
 */
class SermonProcessingStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'processing_id' => Str::uuid(),
            'step' => $this->faker->randomElement(['transcription', 'analysis', 'segmentation', 'storage']),
            'status' => 'started',
            'message' => null,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    /**
     * Indicate that the step is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'started_at' => now()->subHours(1),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the step failed.
     */
    public function failed(string $errorMessage = 'Processing failed'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'message' => $errorMessage,
            'started_at' => now()->subHours(1),
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the step was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'started_at' => now()->subHours(1),
            'completed_at' => now(),
        ]);
    }
}
