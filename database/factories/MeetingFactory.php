<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Carbon\Carbon; // Correct placement

class MeetingFactory extends Factory
{
  public function definition()
  {
    $title = $this->faker->words(3, true);

    return [
      'slug' => Str::slug($title),
      'type' => $this->faker->randomElement([
        'SundayAndBibleStudies',
        'ChildrenAndYoungPeople',
        'Adults',
        'Occasional'
      ]),
      'StartTime' => $this->faker->optional()->time(),
      'EndTime' => $this->faker->optional()->time(),
      'day' => $this->faker->dayOfWeek(), // Relevant for recurring meetings
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
      'meeting_date' => null, // Default to null for general meetings
      'is_recurring' => false, // Default to false
    ];
  }

  /**
   * Indicate that the meeting is on a specific date.
   *
   * @param \Carbon\Carbon $date
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function onDate(Carbon $date)
  {
    return $this->state(function (array $attributes) use ($date) {
      return [
        'meeting_date' => $date,
        'is_recurring' => false, // Typically, a specific date meeting is not recurring in the same way
        'day' => $date->format('l'), // Set day of week from the specific date
      ];
    });
  }

  /**
   * Indicate that the meeting is not recurring and has a specific date.
   *
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function notRecurring()
  {
    return $this->state(function (array $attributes) {
      $date = Carbon::instance($this->faker->dateTimeThisMonth());
      return [
        'is_recurring' => false,
        'meeting_date' => $date,
        'day' => $date->format('l'),
      ];
    });
  }

  /**
   * Indicate that the meeting is recurring.
   *
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function recurring()
  {
    return $this->state(function (array $attributes) {
      return [
        'is_recurring' => true,
        'meeting_date' => null, // Recurring meetings might not have one specific date
      ];
    });
  }
}
