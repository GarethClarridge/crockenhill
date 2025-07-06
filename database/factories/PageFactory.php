<?php

namespace Database\Factories;

use App\Enums\PageArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PageFactory extends Factory
{
    public function definition()
    {
        $heading = $this->faker->sentence(3);

        return [
            'slug' => Str::slug($heading),
            'heading' => $heading,
            'description' => $this->faker->sentence(10),
            'area' => $this->faker->randomElement(PageArea::values()),
            'body' => $this->faker->paragraphs(3, true),
            'admin' => $this->faker->randomElement(['yes', 'no']),
            'markdown' => $this->faker->optional()->paragraphs(2, true),
            'navigation' => $this->faker->boolean(70),
        ];
    }

    public function isNavigation(bool $isNav = true): Factory
    {
        return $this->state(function (array $attributes) use ($isNav) {
            return [
                'navigation' => $isNav,
            ];
        });
    }

    public function isNotNavigation(): Factory
    {
        return $this->isNavigation(false);
    }

    public function inArea(string $areaName): Factory
    {
        return $this->state(function (array $attributes) use ($areaName) {
            if (! in_array($areaName, PageArea::values(), true)) {
                throw new \InvalidArgumentException("Invalid area: $areaName");
            }

            return [
                'area' => $areaName,
            ];
        });
    }
}
