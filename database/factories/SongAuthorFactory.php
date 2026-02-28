<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SongAuthor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SongAuthor>
 */
class SongAuthorFactory extends Factory
{
    protected $model = SongAuthor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = $this->faker->firstName();
        $lastName = $this->faker->lastName();

        return [
            'display_name' => "{$firstName} {$lastName}",
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    }
}
