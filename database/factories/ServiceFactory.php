<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
  public function definition()
  {
    $date = $this->faker->date();
    $type = $this->faker->randomElement(['morning', 'evening']);

    return [
      'name' => $this->faker->words(3, true) . ' Service',
      'date' => $date,
      'type' => $type,
      'start_time' => $this->faker->time('H:i:s'), // Added start_time
      'video' => 'videos/' . $date . '_' . $type . '_service.mp4',
      'audio' => 'audio/' . $date . '_' . $type . '_service.mp3',
      'is_active' => true, // Default to active
    ];
  }

  /**
   * Indicate that the service is active.
   *
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function active()
  {
    return $this->state(function (array $attributes) {
      return [
        'is_active' => true,
      ];
    });
  }

  /**
   * Indicate that the service is inactive.
   *
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function inactive()
  {
    return $this->state(function (array $attributes) {
      return [
        'is_active' => false,
      ];
    });
  }

  /**
   * Indicate that the service is at a specific time.
   *
   * @param string $time (e.g., '10:30:00')
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function atTime(string $time)
  {
    return $this->state(function (array $attributes) use ($time) {
      return [
        'start_time' => $time,
      ];
    });
  }
}
