<?php

namespace Database\Factories;

use Crockenhill\PlayDate;
use Crockenhill\Sermon;
use Crockenhill\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlayDateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PlayDate::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        // Default to sermon, but allow song_id to be set
        return [
            'sermon_id' => null, //Sermon::factory(),
            'song_id' => null,   // Will be set by states or if no sermon_id
            'played_on' => $this->faker->date(),
        ];
    }

    /**
     * Associate the play date with a specific sermon.
     *
     * @param \Crockenhill\Sermon|int $sermon
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forSermon($sermon)
    {
        return $this->state(function (array $attributes) use ($sermon) {
            return [
                'sermon_id' => $sermon instanceof Sermon ? $sermon->id : $sermon,
                'song_id' => null,
            ];
        });
    }

    /**
     * Associate the play date with a specific song.
     *
     * @param \Crockenhill\Song|int $song
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function forSong($song)
    {
        return $this->state(function (array $attributes) use ($song) {
            return [
                'sermon_id' => null,
                'song_id' => $song instanceof Song ? $song->id : $song,
            ];
        });
    }
}
