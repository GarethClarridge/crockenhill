<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChurchServiceItem>
 */
class ChurchServiceItemFactory extends Factory
{
    protected $model = ChurchServiceItem::class;

    public function configure(): static
    {
        $pendingPositions = [];

        return $this->afterMaking(function (ChurchServiceItem $item) use (&$pendingPositions): void {
            $churchServiceId = $item->church_service_id;

            if (! is_int($churchServiceId)) {
                return;
            }

            $requestedPosition = is_int($item->position) && $item->position > 0
                ? $item->position
                : 1;

            $occupiedPositions = $pendingPositions[$churchServiceId] ?? [];

            if (
                isset($occupiedPositions[$requestedPosition])
                || ChurchServiceItem::query()
                    ->where('church_service_id', $churchServiceId)
                    ->whereNull('deleted_at')
                    ->where('position', $requestedPosition)
                    ->exists()
            ) {
                $maxPendingPosition = $occupiedPositions === [] ? 0 : max(array_keys($occupiedPositions));
                $maxPersistedPosition = (int) ChurchServiceItem::query()
                    ->where('church_service_id', $churchServiceId)
                    ->whereNull('deleted_at')
                    ->max('position');

                $requestedPosition = max($maxPendingPosition, $maxPersistedPosition) + 1;
                $item->position = $requestedPosition;
            }

            $pendingPositions[$churchServiceId][$requestedPosition] = true;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'church_service_id' => ChurchService::factory(),
            'position' => function (array $attributes): int {
                $churchServiceId = $attributes['church_service_id'] ?? null;

                if (! is_numeric($churchServiceId)) {
                    return 1;
                }

                $serviceId = (int) $churchServiceId;
                $maxPosition = ChurchServiceItem::query()
                    ->where('church_service_id', $serviceId)
                    ->max('position');

                return is_numeric($maxPosition) ? ((int) $maxPosition + 1) : 1;
            },
            // Deterministic by default: a random `type` here is faker-sequence
            // dependent, which silently changes item-sync/song-linking behaviour
            // between runs. Use the ->song()/->bible()/->presentation() states
            // when a specific type matters.
            'type' => 'custom',
            'section_type' => null,
            'source' => ChurchServiceItemSource::OpenLp->value,
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

    public function livestream(): static
    {
        return $this->state(fn (): array => [
            'source' => ChurchServiceItemSource::Livestream->value,
            'openlp_search_title' => null,
        ]);
    }

    public function song(): static
    {
        return $this->state(fn (): array => ['type' => 'songs']);
    }

    public function bible(): static
    {
        return $this->state(fn (): array => ['type' => 'bibles']);
    }

    public function presentation(): static
    {
        return $this->state(fn (): array => ['type' => 'presentations']);
    }
}
