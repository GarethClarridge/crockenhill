<?php

namespace Database\Factories;

use App\Enums\SermonService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SermonFactory extends Factory
{
    public function definition()
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

    public function withDate(\Carbon\Carbon $date): Factory
    {
        return $this->state(function (array $attributes) use ($date) {
            return [
                'date' => $date,
            ];
        });
    }

    public function inSeries(string $seriesTitle): Factory
    {
        return $this->state(function (array $attributes) use ($seriesTitle) {
            return [
                'series' => $seriesTitle,
            ];
        });
    }

    public function byPreacher(string $preacherName): Factory
    {
        return $this->state(function (array $attributes) use ($preacherName) {
            return [
                'preacher' => $preacherName,
            ];
        });
    }
}
