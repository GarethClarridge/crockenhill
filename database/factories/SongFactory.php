<?php

namespace Database\Factories;

use Crockenhill\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

class SongFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Song::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'title' => $this->faker->sentence(3),
            'slug' => $this->faker->slug(3), // Assuming slug exists or will be added
            'lyrics' => $this->faker->paragraphs(3, true),
            'copyright' => $this->faker->company,
            'ccli_number' => $this->faker->optional()->randomNumber(7),
            // Add any other relevant fields based on actual DB schema
        ];
    }

    /**
     * Indicate that the song has specific lyrics.
     *
     * @param string $lyrics
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withLyrics(string $lyrics)
    {
        return $this->state(function (array $attributes) use ($lyrics) {
            return [
                'lyrics' => $lyrics,
            ];
        });
    }

    /**
     * Indicate that the song has no lyrics.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function withoutLyrics()
    {
        return $this->state(function (array $attributes) {
            return [
                'lyrics' => null,
            ];
        });
    }
}
