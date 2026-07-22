<x-admin.list-shell
    title="Songs"
    description="Most-used songs linked from imported orders of service"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.add', ['intent' => 'plan']) }}" variant="primary" icon="plus" inline>
            Add order of service
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-admin.filter-bar loading-target="search, serviceFilter, dateFrom, dateTo, resetFilters">
            <x-input
                placeholder="Search title, canonical key, author, alternate title, CCLI..."
                wire:model.live.debounce="search"
                icon="magnifying-glass"
                clearable
                class="w-80"
                shortcut="slash" />

            <x-select
                placeholder="Service"
                wire:model.live="serviceFilter"
                :options="collect($services)->map(fn($service) => ['id' => $service->value, 'name' => $service->label()])->toArray()"
                class="w-44" />

            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600" for="songs-date-from">From</label>
                <x-input
                    id="songs-date-from"
                    type="date"
                    wire:model.live="dateFrom"
                    class="w-44" />
            </div>

            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600" for="songs-date-to">To</label>
                <x-input
                    id="songs-date-to"
                    type="date"
                    wire:model.live="dateTo"
                    class="w-44" />
            </div>
        </x-admin.filter-bar>
    </x-slot:filters>

    <x-slot:pagination>
        {{ $songs->links(data: ['scrollTo' => '#admin-list-results']) }}
    </x-slot:pagination>

    @php
        $headers = [
            ['label' => 'Song', 'column' => 'title'],
            ['label' => 'Authors', 'column' => null],
            ['label' => 'Usage', 'column' => 'usage_count'],
            ['label' => 'Last used', 'column' => 'last_used_date'],
        ];
    @endphp

    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                @foreach($headers as $header)
                    <x-admin.sortable-header
                        :column="$header['column'] ?? ''"
                        :label="$header['label']"
                        :sortable="$header['column'] !== null"
                        :sortBy="$sortBy"
                        :sortDirection="$sortDirection"
                    />
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 bg-white">
            @forelse($songs as $song)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.services.songs.show', $song) }}" wire:navigate class="font-medium hover:text-cbc-teal">{{ $song->title }}</a>
                        @if($song->alternate_title)
                            <p class="text-xs text-gray-500">Alt: {{ $song->alternate_title }}</p>
                        @endif
                        @if($song->ccli_number)
                            <p class="text-xs text-gray-500">CCLI: {{ $song->ccli_number }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-700">
                        {{ $song->authors->pluck('display_name')->implode(', ') ?: '-' }}
                    </td>
                    <td class="px-4 py-3 text-sm font-medium">
                        {{ (int) ($song->usage_count ?? 0) }}
                    </td>
                    <td class="px-4 py-3 text-sm">
                        @if($song->last_used_date)
                            {{ \Illuminate\Support\Carbon::parse($song->last_used_date)->format('j M Y') }}
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <x-admin.empty-state
                    colspan="4"
                    title="No songs found"
                    :hasFilters="$hasFilters"
                >
                    @if(!$hasFilters)
                        <p class="text-sm text-gray-500">
                            No songs available yet. Run song sync and link commands first.
                        </p>
                    @endif
                </x-admin.empty-state>
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>
