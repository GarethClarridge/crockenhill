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
      'date' => $date,
      'type' => $type,
      'video' => 'videos/' . $date . '_' . $type . '_service.mp4',
      'audio' => 'audio/' . $date . '_' . $type . '_service.mp3',
    ];
  }
}
