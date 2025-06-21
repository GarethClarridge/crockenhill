<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SongFactory extends Factory
{
  public function definition()
  {
    $majorCategories = [
      'Psalms',
      'Approaching God',
      'Children\'s',
      'Christ\'s Lordship over all of life',
      'The Bible',
      'The Christian life',
      'The church',
      'The Father',
      'The future',
      'The gospel',
      'The Holy Spirit',
      'The Son'
    ];

    $minorCategories = [
      'The eternal Trinity',
      'Adoration and thanksgiving',
      'Creator and sustainer',
      'Morning and evening',
      'The Lord\'s Day',
      'His character',
      'His providence',
      'His love',
      'His birth and childhood',
      'His life and ministry',
      'His suffering and death',
      'His resurrection',
      'New birth and new life',
      'Repentance and faith',
      'Union with Christ',
      'Love for Christ',
    ];

    return [
      'praise_number' => $this->faker->optional()->numerify('###'),
      'title' => $this->faker->sentence(3),
      'author' => $this->faker->optional()->name(),
      'lyrics' => $this->faker->optional()->paragraphs(4, true),
      'copyright' => $this->faker->optional()->year() . ' ' . $this->faker->company(),
      'alternative_title' => $this->faker->optional()->sentence(2),
      'current' => $this->faker->boolean(80),
      'notes' => $this->faker->optional()->text(200),
      'major_category' => $this->faker->optional()->randomElement($majorCategories),
      'minor_category' => $this->faker->optional()->randomElement($minorCategories),
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
}
