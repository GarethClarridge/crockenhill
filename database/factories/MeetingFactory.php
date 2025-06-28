<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Carbon\Carbon; // Import Carbon

class MeetingFactory extends Factory
{
  public function definition()
  {
    $title = $this->faker->words(3, true); // This local $title is for the slug
    $isRecurring = $this->faker->boolean(30); // 30% chance of being recurring

    return [
      'slug' => Str::slug($title), // slug is still based on a generated title concept
      'type' => $this->faker->randomElement([
        'SundayAndBibleStudies',
        'ChildrenAndYoungPeople',
        'Adults',
        'Occasional'
      ]),
      'StartTime' => $this->faker->optional()->time(),
      'EndTime' => $this->faker->optional()->time(),
      // 'day' is now set by onDate, or by meeting_date's day in definition
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

      // New fields
      'meeting_date' => $meetingDate = $this->faker->dateTimeBetween('+0 days', '+1 year'),
      'day' => Carbon::parse($meetingDate)->format('l'), // Set day based on meeting_date
      'is_recurring' => $isRecurring,
      'frequency' => $isRecurring ? $this->faker->randomElement(['daily', 'weekly', 'monthly', 'annually']) : null,
    ];
  }

  public function onDate(Carbon $date): Factory
  {
    return $this->state(function (array $attributes) use ($date) {
      return [
        'meeting_date' => $date,
        'day' => $date->format('l'), // Update day based on the specific date
      ];
    });
  }

  public function recurring($frequency = 'weekly'): Factory
  {
    return $this->state(function (array $attributes) use ($frequency) {
      return [
        'is_recurring' => true,
        'frequency' => $frequency,
      ];
    });
  }

  public function notRecurring(): Factory
  {
    return $this->state(function (array $attributes) {
      return [
        'is_recurring' => false,
        'frequency' => null,
      ];
    });
  }

  public function upcoming(): Factory
  {
    return $this->state(function (array $attributes) {
      $date = Carbon::instance($this->faker->dateTimeBetween('+1 day', '+1 year'));
      return [
        'meeting_date' => $date,
        'day' => $date->format('l'),
      ];
    });
  }

  public function past(): Factory
  {
    return $this->state(function (array $attributes) {
      $date = Carbon::instance($this->faker->dateTimeBetween('-1 year', '-1 day'));
      return [
        'meeting_date' => $date,
        'day' => $date->format('l'),
      ];
    });
  }
}
