<?php

namespace Database\Factories;

use Crockenhill\Sermon;
use Crockenhill\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SermonFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Sermon::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $title = $this->faker->sentence(3);
        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'service_id' => Service::factory(),
            'preacher' => $this->faker->name,
            'series' => $this->faker->words(3, true),
            'date' => $this->faker->dateTimeThisYear(),
            'description' => $this->faker->paragraph,
            'points' => json_encode([$this->faker->sentence, $this->faker->sentence, $this->faker->sentence]),
            'audio_url' => $this->faker->url . '.mp3',
            'video_url' => $this->faker->optional()->url,
            'manuscript_url' => $this->faker->optional()->url . '.pdf',
        ];
    }

    /**
     * Indicate that the sermon has a specific date.
     *
     * @param  \DateTimeInterface|string  $date
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withDate($date)
    {
        return $this->state(function (array $attributes) use ($date) {
            return [
                'date' => $date,
            ];
        });
    }

    /**
     * Indicate that the sermon belongs to a specific service.
     *
     * @param  \Crockenhill\Service|int  $service
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forService($service)
    {
        return $this->state(function (array $attributes) use ($service) {
            return [
                'service_id' => $service instanceof Service ? $service->id : $service,
            ];
        });
    }

    /**
     * Indicate that the sermon is part of a specific series.
     *
     * @param  string  $seriesTitle
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function inSeries(string $seriesTitle)
    {
        return $this->state(function (array $attributes) use ($seriesTitle) {
            return [
                'series' => $seriesTitle,
            ];
        });
    }

    /**
     * Indicate that the sermon was given by a specific preacher.
     *
     * @param  string  $preacherName
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function byPreacher(string $preacherName)
    {
        return $this->state(function (array $attributes) use ($preacherName) {
            return [
                'preacher' => $preacherName,
            ];
        });
    }
}
