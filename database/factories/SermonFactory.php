<?php

namespace Database\Factories;

use App\Enums\SermonService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SermonFactory extends Factory
{
    public function definition(): array
    {
        $title = $this->faker->sentence(4);

        return [
            'date' => $this->faker->date(),
            'service' => $this->faker->randomElement([SermonService::MORNING->value, SermonService::EVENING->value, SermonService::OTHER->value]),
            'audio_file_path' => Str::slug($title).'.mp3',
            'filetype' => 'mp3',
            'title' => $title,
            'slug' => Str::slug($title),
            'reference' => $this->faker->randomElement([
                'Matthew 5:1-12',
                'John 3:16',
                'Romans 8:28-39',
                'Psalm 23',
                'Genesis 1:1-31',
                '1 Corinthians 13',
            ]),
            'preacher' => $this->faker->randomElement([
                'Mark Drury',
                'John Smith',
                'David Johnson',
                'Michael Brown',
                'Paul Wilson',
            ]),
            'series' => $this->faker->optional()->randomElement([
                'Gospel of John',
                'Psalms',
                'Romans',
                'Life of David',
                'Parables of Jesus',
            ]),
            'points' => $this->faker->optional()->randomElement([
                null,
                [$this->faker->sentence(), $this->faker->sentence(), $this->faker->sentence()],
            ]),
        ];
    }

    public function withDate(\Carbon\Carbon $date): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => $date,
        ]);
    }

    public function inSeries(string $seriesTitle): static
    {
        return $this->state(fn (array $attributes) => [
            'series' => $seriesTitle,
        ]);
    }

    public function byPreacher(string $preacherName): static
    {
        return $this->state(fn (array $attributes) => [
            'preacher' => $preacherName,
        ]);
    }

    public function withPreacher(\App\Models\Preacher $preacher): static
    {
        return $this->state(fn () => [
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'preacher_source' => 'manual',
        ]);
    }

    /**
     * Sermon has a transcript file path set (automated processing).
     */
    public function withTranscript(): static
    {
        return $this->state(fn (array $attributes) => [
            'transcript_file_path' => 'transcripts/'.($attributes['slug'] ?? Str::random(8)).'.txt',
        ]);
    }

    /**
     * Sermon has a video file path set.
     */
    public function withVideo(): static
    {
        return $this->state(fn (array $attributes) => [
            'video_file_path' => 'sermons/video/'.($attributes['slug'] ?? Str::random(8)).'.mp4',
        ]);
    }

    /**
     * Sermon came from a livestream recording.
     */
    public function fromLivestream(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => 'livestream',
            'segment_start_time' => $this->faker->numberBetween(0, 3600),
            'segment_end_time' => $this->faker->numberBetween(3601, 7200),
        ]);
    }

    /**
     * Sermon needs preacher review (automated assignment uncertain).
     */
    public function needsPreacherReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'needs_preacher_review' => true,
        ]);
    }
}
