<?php

namespace Database\Factories;

use Crockenhill\Meeting;
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
        $name = $this->faker->words(3, true) . ' Meeting';
        return [
            'slug' => $this->faker->slug,
            'type' => $this->faker->word,
            'StartTime' => $this->faker->time('H:i:s'),
            'EndTime' => $this->faker->time('H:i:s'),
            'day' => $this->faker->dayOfWeek,
            'location' => $this->faker->address,
            'who' => $this->faker->name,
            'LeadersPhone' => $this->faker->optional()->phoneNumber,
            'LeadersEmail' => $this->faker->optional()->email,
            'pictures' => $this->faker->boolean(50) ? '1' : '0',
            // Retaining fields from original factory for now,
            // as they might be used elsewhere or for different meeting types.
            // Consider removing/refactoring if confirmed unused.
            'name' => $name, // If 'type' is more like a category, 'name' might still be relevant.
            'meeting_date' => $this->faker->dateTimeThisYear(),
            'description' => $this->faker->optional()->paragraph,
            'is_recurring' => $this->faker->boolean(20),
            'frequency' => null,
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
