<?php

namespace Database\Factories;

use App\Enums\SampleSource;
use App\Models\SpeakerProfile;
use App\Models\SpeakerSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpeakerSample>
 */
class SpeakerSampleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'speaker_profile_id' => SpeakerProfile::factory(),
            'sermon_id' => null,
            'media_processing_log_id' => null,
            'embedding' => $this->generateEmbedding(),
            'duration_seconds' => $this->faker->randomFloat(1, 30.0, 120.0),
            'quality_score' => $this->faker->optional()->randomFloat(4, 0.5, 1.0),
            'source' => SampleSource::UploadAuto->value,
            'approved' => false,
        ];
    }

    public function approved(): Factory
    {
        return $this->state(fn () => ['approved' => true]);
    }

    public function backfill(): Factory
    {
        return $this->state(fn () => [
            'source' => SampleSource::Backfill->value,
            'approved' => true,
        ]);
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
