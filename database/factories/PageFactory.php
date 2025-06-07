<?php

namespace Database\Factories;

use Crockenhill\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Page::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $title = $this->faker->sentence;
        $slug = Str::slug($title);
        return [
            'title' => $title,
            'slug' => $slug,
            'area' => $this->faker->optional()->randomElement(['community', 'church', 'christ']), // Add default area
            'heading' => $title,
            'description' => $this->faker->paragraph,
            'content' => $this->faker->paragraphs(3, true),
            'navigation' => $this->faker->boolean(25),
        ];
    }
}
