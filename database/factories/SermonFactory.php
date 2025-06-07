<?php

namespace Database\Factories;

use Crockenhill\Sermon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SermonFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Sermon::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $title = $this->faker->sentence(4);
        return [
            'title' => $title,
            'filename' => Str::slug($title),
            'filetype' => 'mp3',
            'date' => $this->faker->date(),
            'service' => $this->faker->randomElement(['morning', 'evening']),
            'slug' => Str::slug($title),
            'series' => $this->faker->optional()->sentence(2),
            'reference' => $this->faker->optional()->regexify('[1-2]\s[A-Za-z]{3,}\s[0-9]{1,2}:[0-9]{1,2}(-[0-9]{1,2})?'), // e.g., 1 Sam 10:1-3
            'preacher' => $this->faker->name,
            'points' => $this->faker->optional()->paragraph,
        ];
    }
}
