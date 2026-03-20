<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="font-display text-3xl">Calendar Events</h1>
            <p class="text-gray-600">Manage and categorize calendar events</p>
        </div>
        <x-button link="{{ route('admin.calendar.sync') }}" variant="secondary" icon="arrow-path" inline>
            Sync Calendar
        </x-button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4">
        <x-input placeholder="Search events..." wire:model.live.debounce="search"
            icon="magnifying-glass" clearable class="w-64" />

        <x-select placeholder="All Meetings" wire:model.live="meetingFilter"
            :options="$meetings->map(fn($name, $slug) => ['id' => $slug, 'name' => $name])->values()->toArray()"
            class="w-48" />

        <x-toggle label="Uncategorized Only" wire:model.live="uncategorizedOnly" />
        <x-toggle label="Upcoming Only" wire:model.live="upcomingOnly" />

        <div x-show="$wire.hasFilters" x-transition x-cloak>
            <x-form-button variant="ghost" size="sm" icon="x-mark" wire:click="resetFilters">
                Clear Filters
            </x-form-button>
        </div>
    </div>

    {{-- Table --}}
    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        @foreach($headers as $header)
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                {{ $header['label'] }}
                            </th>
                        @endforeach
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($events as $event)
                        <tr class="hover:bg-gray-50">
                            {{-- Title --}}
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $event->title }}</p>
                                @if($event->speaker)
                                    <p class="text-sm text-gray-500">Speaker: {{ $event->speaker }}</p>
                                @endif
                            </td>
                            {{-- Date & Time --}}
                            <td class="px-4 py-3">
                                <p class="font-medium">{{ $event->start_datetime->format('j M Y') }}</p>
                                <p class="text-sm text-gray-500">
                                    {{ $event->start_datetime->format('H:i') }} - {{ $event->end_datetime->format('H:i') }}
                                </p>
                            </td>
                            {{-- Meeting --}}
                            <td class="px-4 py-3">
                                @if($event->meeting_slug)
                                    <div>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            {{ $event->meeting->page?->heading ?? $event->meeting_slug }}
                                        </span>
                                        @if($event->is_categorized_automatically)
                                            <span class="text-xs text-gray-500">(auto)</span>
                                        @endif
                                    </div>
                                @else
                                    <div class="flex gap-2 items-center">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                            Uncategorized
                                        </span>
                                        <select wire:change="categorize({{ $event->id }}, $event.target.value)"
                                            class="text-xs rounded-md border-gray-300 shadow-sm focus:border-cbc-teal focus:ring-cbc-teal w-40">
                                            <option value="">Categorize...</option>
                                            @foreach($meetings as $slug => $name)
                                                <option value="{{ $slug }}">{{ $name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            </td>
                            {{-- Location --}}
                            <td class="px-4 py-3">
                                <span class="text-sm">{{ $event->location ?? '-' }}</span>
                            </td>
                            {{-- Status --}}
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $event->status === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                    {{ ucfirst($event->status) }}
                                </span>
                            </td>
                            {{-- Actions --}}
                            <td class="px-4 py-3 text-right">
                                <div class="flex gap-1 justify-end" role="group" aria-label="Actions for {{ $event->title }}">
                                    <x-button link="{{ route('admin.calendar-events.edit', $event) }}" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit event: {{ $event->title }}" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-admin.empty-state
                            colspan="{{ count($headers) + 1 }}"
                            title="No events found"
                            :hasFilters="$hasFilters"
                        />
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $events->links() }}
        </div>
    </x-card>
</div>
