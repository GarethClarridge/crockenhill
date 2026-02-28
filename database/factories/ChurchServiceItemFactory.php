<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChurchServiceItem>
 */
class ChurchServiceItemFactory extends Factory
{
    protected $model = ChurchServiceItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'church_service_id' => ChurchService::factory(),
            'position' => $this->faker->numberBetween(1, 12),
            'type' => $this->faker->randomElement(['songs', 'bibles', 'presentations', 'custom']),
            'title' => $this->faker->sentence(3),
            'source_title' => $this->faker->optional()->sentence(4),
            'openlp_search_title' => $this->faker->optional()->slug(),
            'metadata' => $this->faker->optional()->randomElement([
                null,
                ['authors' => $this->faker->name()],
                ['theme' => 'Reading'],
            ]),
        ];
    }
}
