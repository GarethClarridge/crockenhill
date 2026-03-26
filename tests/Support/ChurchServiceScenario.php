<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;

final class ChurchServiceScenario
{
    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $items = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function state(array $attributes): self
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return $this;
    }

    public function manual(): self
    {
        $this->attributes['source'] = 'manual';

        return $this;
    }

    public function openLp(): self
    {
        $this->attributes['source'] = 'openlp';

        return $this;
    }

    public function livestream(): self
    {
        $this->attributes['source'] = 'livestream';

        return $this;
    }

    public function needsReview(bool $needsReview = true): self
    {
        $this->attributes['needs_review'] = $needsReview;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function withItem(array $attributes = []): self
    {
        $this->items[] = $attributes;

        return $this;
    }

    public function create(): ChurchService
    {
        $service = ChurchService::factory()->create($this->attributes);

        foreach ($this->items as $index => $attributes) {
            ChurchServiceItem::factory()->create(array_merge([
                'church_service_id' => $service->id,
                'position' => $index + 1,
            ], $attributes));
        }

        return $service->fresh('items');
    }
}
