<?php

namespace Database\Factories;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Models\Sermon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MediaProcessingLog>
 */
class MediaProcessingLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $processingType = $this->faker->randomElement(MediaType::cases());

        return [
            'processing_id' => $this->faker->uuid(),
            'processing_type' => $processingType,
            'original_filename' => $this->faker->regexify('[0-9]{4}-[0-9]{2}-[0-9]{2}').'_sermon.mp3',
            'status' => $this->faker->randomElement([
                ProcessingStatus::PENDING,
                ProcessingStatus::PROCESSING,
                ProcessingStatus::COMPLETED,
                ProcessingStatus::FAILED,
                ProcessingStatus::CANCELLED,
            ]),
            'current_step' => $this->faker->optional()->randomElement([
                'metadata_extraction',
                'audio_transcription',
                'content_analysis',
                'sermon_creation',
                'completed',
            ]),
            'error_message' => $this->faker->optional(0.2)->sentence(),
            'sermon_id' => null,
            'owner_user_id' => null,
            // Add source_file_path for audio type to prevent null issues
            'source_file_path' => $processingType === MediaType::Audio
                ? 'sermons/test/'.uniqid().'.mp3'
                : null,
            // Add audio_file_path for video/livestream types
            'audio_file_path' => $processingType !== MediaType::Audio
                ? 'sermons/test/'.uniqid().'.mp3'
                : null,
        ];
    }

    /**
     * Indicate that the processing is for audio type.
     */
    public function audio(): static
    {
        return $this->state(fn (array $attributes) => [
            'processing_type' => MediaType::Audio,
        ]);
    }

    /**
     * Indicate that the processing is for video type.
     */
    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'processing_type' => MediaType::Video,
        ]);
    }

    /**
     * Indicate that the processing is for livestream type.
     */
    public function livestream(): static
    {
        return $this->state(fn (array $attributes) => [
            'processing_type' => MediaType::Livestream,
        ]);
    }

    /**
     * Indicate that the processing is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProcessingStatus::PENDING,
            'current_step' => null,
            'error_message' => null,
            'sermon_id' => null,
        ]);
    }

    /**
     * Indicate that the processing is in progress.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProcessingStatus::PROCESSING,
            'current_step' => $this->faker->randomElement([
                'metadata_extraction',
                'audio_transcription',
                'content_analysis',
            ]),
            'error_message' => null,
        ]);
    }

    /**
     * Indicate that the processing is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProcessingStatus::COMPLETED,
            'current_step' => 'completed',
            'error_message' => null,
            'sermon_id' => fn () => Sermon::factory()->create()->id,
        ]);
    }

    /**
     * Indicate that the processing has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProcessingStatus::FAILED,
            'current_step' => $this->faker->randomElement([
                'metadata_extraction',
                'audio_transcription',
                'content_analysis',
                'sermon_creation',
            ]),
            'error_message' => $this->faker->sentence(),
            'sermon_id' => null,
        ]);
    }

    /**
     * Indicate that the processing was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProcessingStatus::CANCELLED,
            'current_step' => 'cancelled',
            'error_message' => 'Processing cancelled by user',
            'sermon_id' => null,
        ]);
    }

    /**
     * Associate the log with a specific sermon (completed processing).
     */
    public function withSermon(Sermon $sermon): static
    {
        return $this->state(fn (array $attributes) => [
            'sermon_id' => $sermon->id,
            'status' => ProcessingStatus::COMPLETED,
            'current_step' => 'completed',
            'error_message' => null,
        ]);
    }

    /**
     * Associate the log with a specific owner user.
     */
    public function withOwner(\App\Models\User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'owner_user_id' => $user->id,
        ]);
    }

    /**
     * Indicate that the processing log is awaiting manual sermon review.
     */
    public function manualReviewRequired(string $reasonCode = 'no_qualifying_speech_block'): static
    {
        $reasonMessages = [
            'no_qualifying_speech_block' => 'No speech block met the 20-minute sermon threshold.',
            'multiple_qualifying_speech_blocks' => 'Multiple speech blocks met the 20-minute sermon threshold.',
            'ratio_below_threshold' => 'The longest speech block was not at least 1.5x longer than the next-longest speech block.',
            'manual_confidence_review' => 'Sermon auto-selection confidence was insufficient.',
        ];

        $reasonMessage = $reasonMessages[$reasonCode] ?? $reasonMessages['no_qualifying_speech_block'];

        return $this->state(fn (array $attributes) => [
            'processing_type' => MediaType::Livestream,
            'status' => ProcessingStatus::FAILED,
            'current_step' => 'manual_review_required',
            'error_message' => $reasonMessage,
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'required',
                    'reason_code' => $reasonCode,
                    'reason_message' => $reasonMessage,
                    'flagged_at' => now()->toIso8601String(),
                    'speech_segments' => [],
                ],
            ],
        ]);
    }
}
