<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ScriptureReferenceFactory extends Factory
{
  public function definition()
  {
    $books = [
      'Gen',
      'Exo',
      'Lev',
      'Num',
      'Deu',
      'Jos',
      'Jdg',
      'Rut',
      '1Sa',
      '2Sa',
      'Mat',
      'Mar',
      'Luk',
      'Joh',
      'Act',
      'Rom',
      '1Co',
      '2Co',
      'Gal',
      'Eph',
      'Psa',
      'Pro',
      'Ecc',
      'Son',
      'Isa',
      'Jer',
      'Lam',
      'Eze',
      'Dan',
      'Hos',
    ];

    return [
      'reference' => $this->faker->randomElement($books) . ' ' .
        $this->faker->numberBetween(1, 150) . ':' .
        $this->faker->numberBetween(1, 31),
      'song_id' => $this->faker->numberBetween(1, 100), // This should ideally use Song::factory()
    ];
  }

  /**
   * Indicate that the scripture reference has a specific reference string.
   *
   * @param string $reference
   * @return \Illuminate\Database\Eloquent\Factories\Factory
   */
  public function withReference(string $reference)
  {
    return $this->state(function (array $attributes) use ($reference) {
      return [
        'reference' => $reference,
      ];
    });
  }
}
