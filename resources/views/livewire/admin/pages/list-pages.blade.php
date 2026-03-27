<x-admin.list-shell
    title="Pages"
    description="Manage website pages"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.pages.create') }}" variant="primary" icon="plus" inline>
            Create Page
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-input placeholder="Search pages..." wire:model.live.debounce="search" icon="magnifying-glass" clearable class="w-64" shortcut="slash" />

        <x-select
            placeholder="All Areas"
            wire:model.live="areaFilter"
            :options="collect($areas)->map(fn($a) => ['id' => $a->value, 'name' => $a->label()])->toArray()"
            class="w-48"
        />

        <x-select
            placeholder="Navigation"
            wire:model.live="navigationFilter"
            :options="[['id' => '1', 'name' => 'In Nav'], ['id' => '0', 'name' => 'Not in Nav']]"
            class="w-40"
        />

        <div x-show="$wire.hasFilters" x-transition x-cloak>
            <x-form-button variant="ghost" size="sm" icon="x-mark" wire:click="resetFilters">
                Clear Filters
            </x-form-button>
        </div>

        <div x-show="$wire.selected.length > 0" x-transition x-cloak>
            <x-form-button variant="danger" size="sm" icon="trash"
                wire:click="deleteSelected" wire:confirm="Delete selected pages?">
                Delete Selected (<span x-text="$wire.selected.length"></span>)
            </x-form-button>
        </div>
    </x-slot:filters>

    <x-slot:pagination>
        {{ $pages->links() }}
    </x-slot:pagination>

    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="w-10 px-4 py-3">
                    <input type="checkbox"
                        aria-label="Select all pages"
                        class="rounded border-gray-300 text-cbc-teal focus:ring-cbc-teal"
                        wire:click="$set('selected', $event.target.checked ? {{ $pages->pluck('id')->toJson() }} : [])"
                        {{ count($selected) === $pages->count() && $pages->count() > 0 ? 'checked' : '' }} />
                </th>
                @foreach($headers as $header)
                    <x-admin.sortable-header
                        :column="$header['key']"
                        :label="$header['label']"
                        :sortable="$header['sortable']"
                        :sortBy="$sortBy"
                        :sortDirection="$sortDirection"
                    />
                @endforeach
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($pages as $page)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <input type="checkbox" value="{{ $page->id }}" wire:model.live="selected"
                            class="rounded border-gray-300 text-cbc-teal focus:ring-cbc-teal" />
                    </td>
                    {{-- Image --}}
                    <td class="px-4 py-3">
                        @if($page->hasMedia('headings'))
                            <img src="{{ $page->getFirstMediaUrl('headings', 'thumbnail') }}"
                                 alt="Header image for {{ $page->heading }}"
                                 class="w-10 h-10 object-cover rounded-lg"
                                 loading="lazy" />
                        @else
                            <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center" aria-label="No image">
                                <x-heroicon-o-photo class="w-5 h-5 text-gray-300" aria-hidden="true" />
                            </div>
                        @endif
                    </td>
                    {{-- Heading --}}
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ Str::limit($page->heading, 40) }}</p>
                        <p class="text-sm text-gray-500">{{ Str::limit($page->description, 50) }}</p>
                    </td>
                    {{-- Area --}}
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ match($page->area) {
                            \App\Enums\PageArea::CHRIST => 'bg-green-100 text-green-800',
                            \App\Enums\PageArea::CHURCH => 'bg-blue-100 text-blue-800',
                            \App\Enums\PageArea::COMMUNITY => 'bg-amber-100 text-amber-800',
                            \App\Enums\PageArea::MEMBERS => 'bg-purple-100 text-purple-800',
                            \App\Enums\PageArea::SERMONS => 'bg-rose-100 text-rose-800',
                        } }}">
                            {{ $page->area->label() }}
                        </span>
                    </td>
                    {{-- Visibility --}}
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $page->isAdminOnly() ? 'bg-rose-100 text-rose-800' : 'bg-gray-100 text-gray-700' }}">
                            {{ $page->isAdminOnly() ? 'Admin only' : 'Public' }}
                        </span>
                    </td>
                    {{-- Navigation --}}
                    <td class="px-4 py-3">
                        @if($page->navigation)
                            <x-heroicon-o-check-circle class="w-5 h-5 text-green-500" aria-hidden="true" />
                        @else
                            <x-heroicon-o-x-circle class="w-5 h-5 text-gray-300" aria-hidden="true" />
                        @endif
                    </td>
                    {{-- Meeting --}}
                    <td class="px-4 py-3">
                        @if($page->meeting)
                            <x-heroicon-o-calendar class="w-5 h-5 text-blue-500" aria-hidden="true" />
                        @else
                            <span class="text-gray-300">-</span>
                        @endif
                    </td>
                    {{-- Updated --}}
                    <td class="px-4 py-3">
                        <span class="text-sm text-gray-500">{{ $page->updated_at?->diffForHumans() ?? '—' }}</span>
                    </td>
                    {{-- Actions --}}
                    <td class="px-4 py-3 text-right">
                        <div class="flex gap-1 justify-end" role="group" aria-label="Actions for {{ $page->heading }}">
                            <x-button link="{{ route('pages.showPublic', ['area' => $page->area->value, 'slug' => $page->slug]) }}" variant="ghost" size="xs" icon="eye" inline aria-label="View page: {{ $page->heading }}" />
                            <x-button link="{{ route('admin.pages.edit', $page) }}" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit page: {{ $page->heading }}" />
                            <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                wire:click="delete({{ $page->id }})"
                                wire:confirm="Delete '{{ $page->heading }}'?"
                                aria-label="Delete page: {{ $page->heading }}" />
                        </div>
                    </td>
                </tr>
            @empty
                <x-admin.empty-state
                    colspan="{{ count($headers) + 2 }}"
                    title="No pages found"
                    :hasFilters="$hasFilters"
                >
                    @if(!$hasFilters)
                        <x-button link="{{ route('admin.pages.create') }}" variant="primary" icon="plus" inline>
                            Create Page
                        </x-button>
                    @endif
                </x-admin.empty-state>
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>
