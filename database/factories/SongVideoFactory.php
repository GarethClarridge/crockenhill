<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SermonPublicationState;
use App\Models\Song;
use App\Models\SongVideo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SongVideo>
 */
class SongVideoFactory extends Factory
{
    protected $model = SongVideo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'song_id' => Song::factory(),
            'service_section_id' => null,
            'church_service_id' => null,
            'video_file_path' => 'sermons/songs/'.$this->faker->numberBetween(1, 100).'/'.$this->faker->uuid().'.mp4',
            'duration' => $this->faker->randomFloat(2, 60, 600),
            'recorded_date' => $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'is_featured' => false,
            'publication_state' => SermonPublicationState::Published,
        ];
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    public function quarantined(): static
    {
        return $this->state(fn (array $attributes) => [
            'publication_state' => SermonPublicationState::Quarantined,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'service_section_id' => null,
            'church_service_id' => null,
            'recorded_date' => null,
        ]);
    }
}
