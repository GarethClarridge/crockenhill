<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
  public function definition()
  {
    return [
      'name' => $this->faker->name(),
      'email' => $this->faker->unique()->safeEmail(),
      'password' => Hash::make('password'),
      'remember_token' => Str::random(10),
    ];
  }

  /**
   * Indicate that the user is an administrator.
   *
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function admin()
  {
    return $this->state(function (array $attributes) {
      return [
        'is_admin' => true,
      ];
    });
  }
}
