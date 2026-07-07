<x-admin.list-shell
    title="Pages"
    description="Manage website pages"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.pages.create') }}" variant="primary" icon="plus" inline>
            Create page
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-admin.filter-bar loading-target="search, areaFilter, navigationFilter, resetFilters">
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
        </x-admin.filter-bar>

        <div x-show="$wire.selected.length > 0" x-transition x-cloak class="mt-4">
            <x-form-button variant="danger" size="sm" icon="trash"
                wire:click="deleteSelected" wire:confirm="Delete selected pages?">
                Delete Selected (<span x-text="$wire.selected.length"></span>)
            </x-form-button>
        </div>
    </x-slot:filters>

    <x-slot:pagination>
        {{ $pages->links(data: ['scrollTo' => '#admin-list-results']) }}
    </x-slot:pagination>

    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="w-10 px-4 py-3">
                    <input type="checkbox"
                        aria-label="Select all pages"
                        class="rounded border-gray-300 text-cbc-teal focus:ring-cbc-teal focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
                        wire:click="$set('selected', $event.target.checked ? {{ $pages->pluck('id')->toJson() }} : [])"
                        {{ count($selected) === $pages->count() && $pages->isNotEmpty() ? 'checked' : '' }} />
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
                <tr wire:loading.class="opacity-50" wire:target="delete({{ $page->id }})" class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <input type="checkbox" value="{{ $page->id }}" wire:model.live="selected"
                            aria-label="Select page: {{ $page->heading }}"
                            class="rounded border-gray-300 text-cbc-teal focus:ring-cbc-teal focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2" />
                    </td>
                    {{-- Image --}}
                    <td class="px-4 py-3">
                        @if($page->hasMedia('headings'))
                            <img src="{{ $page->getFirstMediaUrl('headings', 'thumbnail') }}"
                                 alt="Thumbnail for {{ $page->heading }}"
                                 class="w-10 h-10 object-cover rounded-lg"
                                 loading="lazy" />
                        @else
                            <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center" title="No image">
                                <x-heroicon-o-photo class="w-5 h-5 text-gray-300" aria-hidden="true" />
                                <span class="sr-only">No image</span>
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
                            \App\Enums\PageArea::Christ => 'bg-green-100 text-green-800',
                            \App\Enums\PageArea::Church => 'bg-blue-100 text-blue-800',
                            \App\Enums\PageArea::Community => 'bg-amber-100 text-amber-800',
                            \App\Enums\PageArea::Members => 'bg-purple-100 text-purple-800',
                            \App\Enums\PageArea::Sermons => 'bg-rose-100 text-rose-800',
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
                            <x-heroicon-o-check-circle class="w-5 h-5 text-green-500" title="In navigation" aria-label="In navigation" />
                        @else
                            <x-heroicon-o-x-circle class="w-5 h-5 text-gray-300" title="Hidden from navigation" aria-label="Hidden from navigation" />
                        @endif
                    </td>
                    {{-- Meeting --}}
                    <td class="px-4 py-3">
                        @if($page->meeting_exists)
                            <x-heroicon-o-calendar class="w-5 h-5 text-blue-500" title="Linked to meeting" aria-label="Linked to meeting" />
                        @else
                            <span class="text-gray-300" title="No linked meeting">-</span>
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
