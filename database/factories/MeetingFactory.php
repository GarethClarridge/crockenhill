<?php

namespace Database\Factories;

use App\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Carbon\Carbon;

class MeetingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Meeting::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->words(3, true) . ' Meeting',
            'meeting_date' => $this->faker->dateTimeThisYear(),
            'location_name' => $this->faker->optional()->company,
            'location_address' => $this->faker->optional()->address,
            'description' => $this->faker->optional()->paragraph,
            'is_recurring' => $this->faker->boolean(20), // Default to mostly not recurring
            'frequency' => null, // e.g., 'weekly', 'monthly', or a more structured format
            // 'next_occurrence_at' => null, // Could be calculated or stored
        ];
    }

    /**
     * Indicate that the meeting is recurring.
     *
     * @param string|null $frequency
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function recurring(string $frequency = 'weekly')
    {
        return $this->state(function (array $attributes) use ($frequency) {
            return [
                'is_recurring' => true,
                'frequency' => $frequency,
            ];
        });
    }

    /**
     * Indicate that the meeting is not recurring.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function notRecurring()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_recurring' => false,
                'frequency' => null,
            ];
        });
    }

    /**
     * Set a specific meeting date.
     *
     * @param \Carbon\Carbon|string $date
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function onDate($date)
    {
        return $this->state(function (array $attributes) use ($date) {
            return [
                'meeting_date' => $date instanceof Carbon ? $date : Carbon::parse($date),
            ];
        });
    }

    /**
     * Indicate that the meeting is in the future.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function upcoming()
    {
        return $this->onDate(Carbon::now()->addWeek());
    }

    /**
     * Indicate that the meeting is in the past.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function past()
    {
        return $this->onDate(Carbon::now()->subWeek());
    }
}
