<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MeetingFactory extends Factory
{
  public function definition()
  {
    $title = $this->faker->words(3, true);

    return [
      'name' => $title, // Added name field
      'slug' => Str::slug($title),
      'type' => $this->faker->randomElement([
        'SundayAndBibleStudies',
        'ChildrenAndYoungPeople',
        'Adults',
        'Occasional'
      ]),
      'meeting_date' => $this->faker->dateTimeBetween('+1 day', '+1 year'),
      'StartTime' => $this->faker->optional()->time(),
      'EndTime' => $this->faker->optional()->time(),
      'day' => $this->faker->dayOfWeek(),
      'is_recurring' => $this->faker->boolean(25),
      'frequency' => $this->faker->optional(0.75, null)->randomElement(['weekly', 'monthly', 'annually']),
      'location' => $this->faker->randomElement([
        'Main Hall',
        'Church Building',
        'Community Center',
        'Youth Room',
        'Prayer Room',
      ]),
      'who' => $this->faker->randomElement([
        'All Ages',
        'Adults Only',
        'Children',
        'Youth',
        'Seniors',
      ]),
      'pictures' => $this->faker->boolean(60),
      'LeadersPhone' => $this->faker->optional()->numerify('##########'),
      'LeadersEmail' => $this->faker->optional()->safeEmail(),
    ];
  }

  /**
   * Indicate a specific meeting date.
   *
   * @param  \Carbon\Carbon  $date
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function onDate(\Carbon\Carbon $date)
  {
    return $this->state(function (array $attributes) use ($date) {
      return [
        'meeting_date' => $date,
      ];
    });
  }

  /**
   * Indicate that the meeting is recurring.
   *
   * @param  bool  $isRecurring
   * @param  string|null  $frequency
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function recurring(bool $isRecurring = true, string $frequency = null)
  {
    return $this->state(function (array $attributes) use ($isRecurring, $frequency) {
      return [
        'is_recurring' => $isRecurring,
        'frequency' => $isRecurring ? ($frequency ?? $this->faker->randomElement(['weekly', 'monthly'])) : null,
      ];
    });
  }
}
