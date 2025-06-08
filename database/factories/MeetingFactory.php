<?php

namespace Database\Factories;

// Corrected model namespace based on project structure
use Crockenhill\Meeting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MeetingFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Meeting::class; // Using the imported class

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->sentence(3);
        $meetingTypes = ['SundayAndBibleStudies', 'ChildrenAndYoungPeople', 'Adults', 'Occasional'];

        return [
            'title' => $title,
            'slug' => Str::slug($title) . '-' . $this->faker->unique()->randomNumber(4), // Ensure unique slug
            'type' => $this->faker->randomElement($meetingTypes),
            'StartTime' => $this->faker->optional()->time('H:i'),
            'EndTime' => $this->faker->optional()->time('H:i'),
            'day' => $this->faker->dayOfWeek,
            'location' => $this->faker->words(2, true),
            'who' => $this->faker->sentence(4),
            'pictures' => $this->faker->boolean(25),
            'LeadersPhone' => $this->faker->optional()->numerify('##########'),
            'LeadersEmail' => $this->faker->optional()->safeEmail,
            // created_at and updated_at are handled automatically
        ];
    }
}
