<?php

namespace Database\Factories;

use App\Enums\ProcessingStatus;
use App\Models\Sermon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SermonProcessingLog>
 */
class SermonProcessingLogFactory extends Factory
{
  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    return [
      'processing_id' => $this->faker->uuid(),
      'original_filename' => $this->faker->regexify('[0-9]{4}-[0-9]{2}-[0-9]{2}') . '_sermon.mp3',
      'status' => $this->faker->randomElement(ProcessingStatus::cases()),
      'current_step' => $this->faker->optional()->randomElement([
        'metadata_extraction',
        'audio_transcription',
        'content_analysis',
        'sermon_creation',
        'completed'
      ]),
      'error_message' => $this->faker->optional(0.2)->sentence(),
      'sermon_id' => $this->faker->optional(0.7)->randomElement([
        null,
        fn() => Sermon::factory()->create()->id
      ]),
    ];
  }

  /**
   * Indicate that the processing is pending.
   */
  public function pending(): static
  {
    return $this->state(fn(array $attributes) => [
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
    return $this->state(fn(array $attributes) => [
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
    return $this->state(fn(array $attributes) => [
      'status' => ProcessingStatus::COMPLETED,
      'current_step' => 'completed',
      'error_message' => null,
      'sermon_id' => fn() => Sermon::factory()->create()->id,
    ]);
  }

  /**
   * Indicate that the processing has failed.
   */
  public function failed(): static
  {
    return $this->state(fn(array $attributes) => [
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
}
