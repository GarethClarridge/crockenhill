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

    /**
     * Indicate that the page is in a specific area.
     *
     * @param  string  $areaName
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function inArea(string $areaName)
    {
        return $this->state(function (array $attributes) use ($areaName) {
            return [
                'area' => $areaName,
            ];
        });
    }

    /**
     * Indicate that the page should be in navigation.
     *
     * @param  bool  $isNav
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function isNavigation(bool $isNav = true)
    {
        return $this->state(function (array $attributes) use ($isNav) {
            return [
                'navigation' => $isNav,
            ];
        });
    }

    /**
     * Indicate that the page should not be in navigation.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function isNotNavigation()
    {
        return $this->state(function (array $attributes) {
            return [
                'navigation' => false,
            ];
        });
    }
}
