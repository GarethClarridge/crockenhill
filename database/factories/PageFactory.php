<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PageFactory extends Factory
{
  public function definition()
  {
    $heading = $this->faker->sentence(3);

    return [
      'slug' => Str::slug($heading),
      'heading' => $heading,
      'description' => $this->faker->sentence(10),
      'area' => $this->faker->randomElement(['main', 'sidebar', 'footer', 'header']),
      'body' => $this->faker->paragraphs(3, true),
      'admin' => $this->faker->randomElement(['yes', 'no']),
      'markdown' => $this->faker->optional()->paragraphs(2, true),
      'navigation' => $this->faker->boolean(70),
    ];
  }

  /**
   * Indicate that the page should be in navigation.
   *
   * @param  bool  $isNav
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function isNavigation(bool $isNav = true)
  {
    return $this->state(function (array $attributes) use ($isNav) {
      return [
        'navigation' => $isNav,
      ];
    });
  }

  /**
   * Indicate that the page is in a specific area.
   *
   * @param  string  $areaName
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function inArea(string $areaName)
  {
    return $this->state(function (array $attributes) use ($areaName) {
      return [
        'area' => $areaName,
      ];
    });
  }
}
