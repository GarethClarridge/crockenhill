<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SongBook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SongBook>
 */
class SongBookFactory extends Factory
{
    protected $model = SongBook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_book_id' => $this->faker->unique()->numberBetween(1, 5000),
            'name' => $this->faker->words(3, true),
            'publisher' => $this->faker->optional()->company(),
        ];
    }
}
