<?php

namespace Database\Factories;

use App\Models\Preacher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Preacher>
 */
class PreacherFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->name();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'image_path' => null,
            'bio' => $this->faker->optional()->paragraph(),
            'is_active' => true,
        ];
    }

    public function inactive(): Factory
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
