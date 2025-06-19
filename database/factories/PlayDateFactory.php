<?php

namespace Database\Factories;

use App\Models\PlayDate; // Corrected namespace
use App\Models\Sermon;   // Corrected namespace
use App\Models\Song;     // Added Song import
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
   * @param \App\Models\Sermon|int $sermon
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function forSermon($sermon)
  {
    return $this->state(function (array $attributes) use ($sermon) {
      return [
        'sermon_id' => $sermon instanceof \App\Models\Sermon ? $sermon->id : $sermon,
        'song_id' => null,
      ];
    });
  }

  /**
   * Associate the play date with a specific song.
   *
   * @param \App\Models\Song|int $song
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function forSong($song)
  {
    return $this->state(function (array $attributes) use ($song) {
      return [
        'sermon_id' => null,
        'song_id' => $song instanceof \App\Models\Song ? $song->id : $song,
      ];
    });
  }
}
