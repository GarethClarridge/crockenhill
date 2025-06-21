<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PlayDateFactory extends Factory
{
  public function definition()
  {
    return [
      'song_id' => $this->faker->numberBetween(1, 100),
      'date' => $this->faker->date(),
      'time' => $this->faker->randomElement(['a', 'p']),
    ];
  }
}
