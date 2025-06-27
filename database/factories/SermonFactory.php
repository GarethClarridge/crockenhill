<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SermonFactory extends Factory
{
  public function definition()
  {
    $title = $this->faker->sentence(4);

    return [
      'service_id' => \App\Models\Service::factory(), // Default to creating a new service
      'date' => $this->faker->date(),
      'service_type_enum' => $this->faker->randomElement(['morning', 'evening']), // Keep for now, might be removable
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
   * Indicate a specific date for the sermon.
   *
   * @param  \Carbon\Carbon  $date
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function withDate(\Carbon\Carbon $date)
  {
    return $this->state(function (array $attributes) use ($date) {
      return [
        'date' => $date,
      ];
    });
  }

  /**
   * Indicate the service for the sermon.
   *
   * @param  \App\Models\Service|int  $service
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function forService($service)
  {
    return $this->state(function (array $attributes) use ($service) {
      $serviceId = $service instanceof \App\Models\Service ? $service->id : $service;
      $serviceInstance = $service instanceof \App\Models\Service ? $service : \App\Models\Service::find($serviceId);
      return [
        'service_id' => $serviceId,
        'service_type_enum' => $serviceInstance ? $serviceInstance->type : $this->faker->randomElement(['morning', 'evening']),
      ];
    });
  }

  /**
   * Indicate the series for the sermon.
   *
   * @param  string  $seriesTitle
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function inSeries(string $seriesTitle)
  {
    return $this->state(function (array $attributes) use ($seriesTitle) {
      return [
        'series' => $seriesTitle,
      ];
    });
  }

  /**
   * Indicate the preacher for the sermon.
   *
   * @param  string  $preacherName
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function byPreacher(string $preacherName)
  {
    return $this->state(function (array $attributes) use ($preacherName) {
      return [
        'preacher' => $preacherName,
      ];
    });
  }
}
