<x-admin.list-shell
    title="Calendar events"
    description="Manage and categorise calendar events"
>
    <x-slot:actions>
        <x-form-button type="button" wire:click="syncCalendar" variant="secondary" icon="arrow-path"
            loading-label="Syncing...">
            Sync now
        </x-form-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-admin.filter-bar loading-target="search, meetingFilter, uncategorizedOnly, upcomingOnly, resetFilters">
            <x-input placeholder="Search events..." wire:model.live.debounce="search"
                icon="magnifying-glass" clearable class="w-64" shortcut="slash" />

            <x-select placeholder="All Meetings" wire:model.live="meetingFilter"
                :options="$meetings->map(fn($name, $slug) => ['id' => $slug, 'name' => $name])->values()->toArray()"
                class="w-48" />

            <x-toggle label="Uncategorised Only" wire:model.live="uncategorizedOnly" />
            <x-toggle label="Upcoming Only" wire:model.live="upcomingOnly" />
        </x-admin.filter-bar>
    </x-slot:filters>

    <x-slot:pagination>
        {{ $events->links(data: ['scrollTo' => '#admin-list-results']) }}
    </x-slot:pagination>

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
                <tr wire:loading.class="opacity-50 pointer-events-none" wire:target="categorize({{ $event->id }})" class="hover:bg-gray-50">
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
                            {{ $event->start_datetime->format('g:ia') }} - {{ $event->end_datetime->format('g:ia') }}
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
                                    Uncategorised
                                </span>
                                <select wire:change="categorize({{ $event->id }}, $event.target.value)"
                                    aria-label="Categorise event: {{ $event->title }}"
                                    class="text-xs rounded-md border-gray-300 shadow-sm focus:border-cbc-teal focus:ring-cbc-teal focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 w-40">
                                    <option value="">Categorise...</option>
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
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $event->status->value === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                            {{ $event->status->label() }}
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
                >
                    @if(!$hasFilters)
                        <x-form-button type="button" wire:click="syncCalendar" variant="primary" icon="arrow-path"
                            loading-label="Syncing...">
                            Sync now
                        </x-form-button>
                    @endif
                </x-admin.empty-state>
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>
