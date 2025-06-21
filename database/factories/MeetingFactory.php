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
      'slug' => Str::slug($title),
      'type' => $this->faker->randomElement([
        'SundayAndBibleStudies',
        'ChildrenAndYoungPeople',
        'Adults',
        'Occasional'
      ]),
      'StartTime' => $this->faker->optional()->time(),
      'EndTime' => $this->faker->optional()->time(),
      'day' => $this->faker->dayOfWeek(),
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
}
