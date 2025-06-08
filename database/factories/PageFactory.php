<?php

namespace Database\Factories;

use Crockenhill\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;

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
        $heading = $this->faker->sentence(3);
        $markdown = $this->faker->paragraphs(3, true);

        $converter = new CommonMarkConverter();
        $htmlBody = $converter->convert($markdown)->getContent();

        return [
            'heading' => $heading,
            'slug' => Str::slug($heading),
            'area' => $this->faker->randomElement(['christ', 'church', 'community']),
            'markdown' => $markdown,
            'body' => $htmlBody,
            'description' => $this->faker->sentence(),
            'navigation' => $this->faker->boolean,
        ];
    }
}
