<?php

namespace Database\Factories;

use Crockenhill\Meeting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str; // <--- ADD THIS LINE

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
        $name = $this->faker->sentence(3);
        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => $this->faker->randomElement(['Regular Meeting', 'Special Event', 'Bible Study']),
            'description' => $this->faker->paragraph,
            'day' => $this->faker->dayOfWeek,
            'StartTime' => $this->faker->time('H:i:s'),
            'EndTime' => $this->faker->optional()->time('H:i:s'),
            'location' => $this->faker->optional()->address,
            'who' => $this->faker->optional()->sentence(4), // e.g., "Open to all members and visitors"
            'LeadersPhone' => $this->faker->optional()->phoneNumber,
            'LeadersEmail' => $this->faker->optional()->safeEmail,
            'pictures' => $this->faker->optional()->word, // Could be a flag like '1' or a path segment
        ];
    }
}
