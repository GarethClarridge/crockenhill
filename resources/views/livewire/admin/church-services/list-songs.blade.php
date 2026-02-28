<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl">Songs</h1>
            <p class="text-gray-600">Most-used songs linked from imported service orders</p>
        </div>

        <div class="flex gap-2">
            <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
                Back to Services
            </x-button>
            <x-button link="{{ route('admin.services.upload') }}" variant="primary" icon="arrow-up-tray" inline>
                Upload Service
            </x-button>
        </div>
    </div>

    <div class="flex flex-wrap gap-4">
        <x-input
            placeholder="Search title, author, alternate title, CCLI..."
            wire:model.live.debounce="search"
            icon="magnifying-glass"
            clearable
            class="w-80" />

        <x-select
            placeholder="Service slot"
            wire:model.live="serviceFilter"
            :options="collect($services)->map(fn($service) => ['id' => $service->value, 'name' => $service->label()])->toArray()"
            class="w-44" />

        <div class="flex items-center gap-2">
            <label class="text-sm text-gray-600" for="songs-date-from">From</label>
            <input
                id="songs-date-from"
                type="date"
                wire:model.live="dateFrom"
                class="rounded-md border-gray-300 shadow-sm sm:text-sm focus:border-green-500 focus:ring-green-500" />
        </div>

        <div class="flex items-center gap-2">
            <label class="text-sm text-gray-600" for="songs-date-to">To</label>
            <input
                id="songs-date-to"
                type="date"
                wire:model.live="dateTo"
                class="rounded-md border-gray-300 shadow-sm sm:text-sm focus:border-green-500 focus:ring-green-500" />
        </div>
    </div>

    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Song</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Authors</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Usage</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Distinct Services</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last Used</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($songs as $song)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $song->title }}</p>
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
                                {{ (int) ($song->services_count ?? 0) }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                @if($song->last_used_date)
                                    {{ \Illuminate\Support\Carbon::parse($song->last_used_date)->format('j M Y') }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-button
                                    link="{{ route('admin.services.songs.show', $song) }}"
                                    variant="ghost"
                                    size="xs"
                                    icon="eye"
                                    inline
                                    aria-label="View song: {{ $song->title }}" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                No songs available yet. Run song sync and link commands first.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $songs->links() }}
        </div>
    </x-card>
</div>
