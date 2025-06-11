<?php

namespace Database\Factories;

use Crockenhill\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Service::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->word,
            'description' => $this->faker->sentence,
            'service_time' => $this->faker->time('H:i:s'), // Default time
            'is_active' => $this->faker->boolean(80), // Default to mostly true
        ];
    }

    /**
     * Indicate that the service is active.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function active()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => true,
            ];
        });
    }

    /**
     * Indicate that the service is inactive.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function inactive()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
            ];
        });
    }

    /**
     * Set a specific service time.
     *
     * @param string $time ('HH:MM:SS' or parsable by Carbon)
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function atTime(string $time)
    {
        return $this->state(function (array $attributes) use ($time) {
            return [
                'service_time' => $time,
            ];
        });
    }
}
