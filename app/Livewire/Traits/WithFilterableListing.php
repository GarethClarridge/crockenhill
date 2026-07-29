<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use Livewire\Component;

/**
 * Centralises filter state tracking and URL query-string reset logic for admin list components.
 *
 * The using class must:
 *  - Also use WithPagination
 *  - Implement filterProperties(): array<string, mixed>
 *    returning a map of property name => default value for all filter properties
 *  - Declare a public string $search = '' property if text search is needed
 *
 * The trait computes $hasFilters automatically in computeHasFilters() which should
 * be called at the top of render().
 *
 * @phpstan-require-extends Component
 *
 * @method void resetPage()
 * @method void reset(string|array<string> $fields = [])
 */
trait WithFilterableListing
{
    public bool $hasFilters = false;

    /**
     * Map of filter property names to their default (reset) values.
     * Example: ['search' => '', 'areaFilter' => null, 'hasVideoFilter' => false]
     *
     * @return array<string, mixed>
     */
    abstract protected function filterProperties(): array;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if ($property !== 'search' && array_key_exists($property, $this->filterProperties())) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(array_keys($this->filterProperties()));
        $this->resetPage();

        if (method_exists($this, 'success')) { // @phpstan-ignore-line
            $this->success('Filters cleared');
        }
    }

    protected function computeHasFilters(): void
    {
        foreach ($this->filterProperties() as $property => $default) {
            /** @var mixed $current */
            $current = $this->{$property};

            if ($current !== $default) {
                $this->hasFilters = true;

                return;
            }
        }

        $this->hasFilters = false;
    }
}
