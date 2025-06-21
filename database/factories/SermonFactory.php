<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Carbon\Carbon; // Added import

class SermonFactory extends Factory
{
  public function definition()
  {
    $title = $this->faker->sentence(4);

    return [
      'date' => $this->faker->date(),
      'service' => $this->faker->randomElement(['morning', 'evening']),
      'filename' => Str::slug($title) . '.mp3',
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
      'points' => $this->faker->optional()->text(500),
    ];
  }

  /**
   * Indicate that the sermon is on a specific date.
   *
   * @param \Carbon\Carbon $date
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function withDate(Carbon $date)
  {
    return $this->state(function (array $attributes) use ($date) {
      return [
        'date' => $date,
      ];
    });
  }
}
