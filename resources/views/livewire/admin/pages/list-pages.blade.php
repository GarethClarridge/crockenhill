<div>
    <x-mary-header title="Pages" subtitle="Manage website pages">
        <x-slot:actions>
            <x-mary-button label="Create Page" icon="o-plus" link="{{ route('admin.pages.create') }}" class="btn-primary" />
        </x-slot:actions>
    </x-mary-header>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <x-mary-input placeholder="Search pages..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable class="w-64" />

        <x-mary-select
            placeholder="All Areas"
            wire:model.live="areaFilter"
            :options="collect($areas)->map(fn($a) => ['id' => $a->value, 'name' => $a->label()])"
            class="w-48"
        />

        <x-mary-select
            placeholder="Navigation"
            wire:model.live="navigationFilter"
            :options="[['id' => '1', 'name' => 'In Nav'], ['id' => '0', 'name' => 'Not in Nav']]"
            class="w-40"
        />

        @if(count($selected) > 0)
            <x-mary-button label="Delete Selected ({{ count($selected) }})" icon="o-trash"
                wire:click="deleteSelected" wire:confirm="Delete {{ count($selected) }} pages?"
                class="btn-error btn-sm" />
        @endif
    </div>

    {{-- Table --}}
    <x-mary-card>
        <x-mary-table :headers="$headers" :rows="$pages" wire:model="selected" selectable>

            @scope('cell_image', $page)
                @if($page->hasMedia('headings'))
                    <img src="{{ $page->getFirstMediaUrl('headings', 'thumbnail') }}"
                         alt="Header image for {{ $page->heading }}"
                         class="w-10 h-10 object-cover rounded-lg" />
                @else
                    <div class="w-10 h-10 bg-base-200 rounded-lg flex items-center justify-center" aria-label="No image">
                        <x-mary-icon name="o-photo" class="w-5 h-5 text-base-content/30" aria-hidden="true" />
                    </div>
                @endif
            @endscope

            @scope('cell_heading', $page)
                <div>
                    <p class="font-medium">{{ Str::limit($page->heading, 40) }}</p>
                    <p class="text-sm text-base-content/60">{{ Str::limit($page->description, 50) }}</p>
                </div>
            @endscope

            @scope('cell_area', $page)
                <x-mary-badge :value="$page->area->label()"
                    class="{{ match($page->area) {
                        \App\Enums\PageArea::CHRIST => 'badge-primary',
                        \App\Enums\PageArea::CHURCH => 'badge-secondary',
                        \App\Enums\PageArea::COMMUNITY => 'badge-accent',
                        \App\Enums\PageArea::MEMBERS => 'badge-info',
                        \App\Enums\PageArea::SERMONS => 'badge-warning',
                    } }}" />
            @endscope

            @scope('cell_navigation', $page)
                <span class="flex items-center gap-1">
                    <x-mary-icon :name="$page->navigation ? 'o-check-circle' : 'o-x-circle'"
                        class="w-5 h-5 {{ $page->navigation ? 'text-success' : 'text-base-content/30' }}"
                        aria-hidden="true" />
                    <span class="sr-only">{{ $page->navigation ? 'Shown in navigation' : 'Not in navigation' }}</span>
                </span>
            @endscope

            @scope('cell_meeting', $page)
                @if($page->meeting)
                    <span class="flex items-center gap-1">
                        <x-mary-icon name="o-calendar" class="w-5 h-5 text-info" aria-hidden="true" />
                        <span class="sr-only">Has linked meeting</span>
                    </span>
                @else
                    <span class="text-base-content/30" aria-label="No linked meeting">-</span>
                @endif
            @endscope

            @scope('cell_updated_at', $page)
                <span class="text-sm text-base-content/60">{{ $page->updated_at->diffForHumans() }}</span>
            @endscope

            @scope('actions', $page)
                <div class="flex gap-1" role="group" aria-label="Actions for {{ $page->heading }}">
                    <x-mary-button icon="o-eye" link="{{ route('pages.showPublic', ['area' => $page->area->value, 'slug' => $page->slug]) }}" external class="btn-ghost btn-xs" aria-label="View page" />
                    <x-mary-button icon="o-pencil" link="{{ route('admin.pages.edit', $page) }}" class="btn-ghost btn-xs" aria-label="Edit page" />
                    <x-mary-button icon="o-trash" wire:click="delete({{ $page->id }})"
                        wire:confirm="Delete '{{ $page->heading }}'?" class="btn-ghost btn-xs text-error" aria-label="Delete page" />
                </div>
            @endscope
        </x-mary-table>

        <div class="mt-4">
            {{ $pages->links() }}
        </div>
    </x-mary-card>
</div>
