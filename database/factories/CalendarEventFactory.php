<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_event_id' => $this->faker->uuid(),
            'meeting_slug' => $this->faker->slug(),
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'speaker' => $this->faker->name(),
            'location' => $this->faker->city(),
            'start_datetime' => $this->faker->dateTimeBetween('+1 day', '+30 days'),
            'end_datetime' => $this->faker->dateTimeBetween('+1 day', '+30 days'),
            'status' => 'confirmed',
            'is_categorized_automatically' => false,
        ];
    }

    /**
     * Indicate that the event is pending categorization.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Indicate that the event was categorized automatically.
     */
    public function automaticallyCategorized(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_categorized_automatically' => true,
        ]);
    }
}
