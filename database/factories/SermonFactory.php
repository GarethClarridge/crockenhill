<?php

namespace Database\Factories;

use App\Enums\SermonContentType;
use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Models\Preacher;
use App\Models\Sermon;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SermonFactory extends Factory
{
    public function definition(): array
    {
        $title = $this->faker->sentence(4);

        return [
            'date' => $this->faker->date(),
            'service' => $this->faker->randomElement([SermonService::Morning->value, SermonService::Evening->value, SermonService::Other->value]),
            'content_type' => SermonContentType::Sermon,
            'publication_state' => SermonPublicationState::Published,
            'audio_file_path' => null,
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
            'show_summary' => true,
            'show_points' => true,
            'download_count' => 0,
        ];
    }

    public function withDate(Carbon $date): static
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

    public function withPreacher(Preacher $preacher): static
    {
        return $this->state(fn () => [
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'preacher_source' => 'manual',
        ]);
    }

    /**
     * Creates a sermon with a raw preacher value that bypasses the DB CHECK constraint.
     * Only safe when the test uses DatabaseMigrations (no wrapping transaction), since
     * ALTER TABLE auto-commits any open transaction.
     */
    public function withRawPreacher(string $preacher): static
    {
        return $this->afterCreating(function (Sermon $model) use ($preacher): void {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE sermons ALTER CHECK sermons_preacher_format_check NOT ENFORCED');
                DB::table('sermons')->where('id', $model->id)->update(['preacher' => $preacher]);
                // Cannot re-enforce while violating rows exist; constraint is restored on next migrate:fresh.
            } else {
                DB::table('sermons')->where('id', $model->id)->update(['preacher' => $preacher]);
            }

            $model->preacher = $preacher;
        });
    }

    /**
     * Sermon has an audio file path set (the default factory leaves audio null).
     */
    public function withAudio(): static
    {
        return $this->state(fn (array $attributes) => [
            'audio_file_path' => 'sermons/'.($attributes['slug'] ?? Str::random(8)).'.mp3',
            'filetype' => 'mp3',
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
        return $this->state(function (array $attributes) {
            $start = $this->faker->numberBetween(0, 3600);

            return [
                'source_type' => SermonSourceType::Livestream,
                'segment_start_time' => $start,
                'segment_end_time' => $start + $this->faker->numberBetween(1800, 3600), // 30-60 mins later
                'preacher_confidence' => $this->faker->randomFloat(2, 0.5, 1.0),
                'duration' => $this->faker->randomFloat(2, 1800, 3600),
            ];
        });
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
