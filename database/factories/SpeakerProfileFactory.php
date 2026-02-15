<?php

namespace Database\Factories;

use App\Models\Preacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SpeakerProfile>
 */
class SpeakerProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'preacher_id' => Preacher::factory(),
            'provider' => 'resemblyzer',
            'model_version' => 'v1.0',
            'centroid_embedding' => $this->generateEmbedding(),
            'sample_count' => $this->faker->numberBetween(1, 10),
            'quality_score' => $this->faker->optional()->randomFloat(4, 0.5, 1.0),
            'accept_threshold' => null,
            'margin_threshold' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): Factory
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forProvider(string $provider): Factory
    {
        return $this->state(fn () => ['provider' => $provider]);
    }

    /**
     * Generate a random 256-dimensional embedding vector.
     *
     * @return list<float>
     */
    private function generateEmbedding(): array
    {
        return array_map(
            fn () => round(mt_rand(-1000, 1000) / 1000, 6),
            range(1, 256)
        );
    }
}
