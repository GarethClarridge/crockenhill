<x-admin.list-shell
    title="Meetings"
    description="Manage church meetings and events"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.meetings.create') }}" variant="primary" icon="plus" inline>
            Create meeting
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-admin.filter-bar loading-target="search, typeFilter, recurringFilter, resetFilters">
            <x-input placeholder="Search meetings..." wire:model.live.debounce="search" icon="magnifying-glass" clearable class="w-64" shortcut="slash" />

            <x-select
                placeholder="All Types"
                wire:model.live="typeFilter"
                :options="collect($types)->map(fn($t) => ['id' => $t->value, 'name' => $t->label()])->toArray()"
                class="w-48"
            />

            <x-select
                placeholder="Recurring"
                wire:model.live="recurringFilter"
                :options="[['id' => '1', 'name' => 'Recurring'], ['id' => '0', 'name' => 'One-time']]"
                class="w-40"
            />
        </x-admin.filter-bar>
    </x-slot:filters>

    <x-slot:pagination>
        {{ $meetings->links(data: ['scrollTo' => '#admin-list-results']) }}
    </x-slot:pagination>

    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
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
            @forelse($meetings as $meeting)
                <tr wire:loading.class="opacity-50 pointer-events-none" wire:target="delete({{ $meeting->id }})" class="hover:bg-gray-50">
                    {{-- Meeting --}}
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $meeting->page?->heading ?? $meeting->slug }}</p>
                        <p class="text-sm text-gray-500">{{ $meeting->who }}</p>
                    </td>
                    {{-- Schedule --}}
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $meeting->day }}</p>
                        @if($meeting->start_time)
                            <p class="text-sm text-gray-500">
                                {{ $meeting->start_time->format('g:ia') }}
                                @if($meeting->end_time)
                                    - {{ $meeting->end_time->format('g:ia') }}
                                @endif
                            </p>
                        @endif
                    </td>
                    {{-- Type --}}
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            {{ $meeting->type->label() }}
                        </span>
                    </td>
                    {{-- Recurring --}}
                    <td class="px-4 py-3">
                        @if($meeting->is_recurring)
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-arrow-path class="w-4 h-4 text-blue-500" />
                                <span class="text-sm">{{ $meeting->frequency?->label() }}</span>
                            </div>
                        @else
                            <span class="text-sm text-gray-300">One-time</span>
                        @endif
                    </td>
                    {{-- Location --}}
                    <td class="px-4 py-3">
                        <span class="text-sm">{{ $meeting->location ?? '-' }}</span>
                    </td>
                    {{-- Actions --}}
                    <td class="px-4 py-3 text-right">
                        <div class="flex gap-1 justify-end" role="group" aria-label="Actions for {{ $meeting->page?->heading ?? $meeting->slug }}">
                            <x-button link="{{ route('meetings.show', $meeting) }}" variant="ghost" size="xs" icon="eye" inline aria-label="View meeting: {{ $meeting->page?->heading ?? $meeting->slug }}" />
                            <x-button link="{{ route('admin.meetings.edit', $meeting) }}" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit meeting: {{ $meeting->page?->heading ?? $meeting->slug }}" />
                            <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                wire:click="delete({{ $meeting->id }})"
                                wire:confirm="Delete '{{ $meeting->page?->heading ?? $meeting->slug }}'?"
                                aria-label="Delete meeting: {{ $meeting->page?->heading ?? $meeting->slug }}" />
                        </div>
                    </td>
                </tr>
            @empty
                <x-admin.empty-state
                    colspan="{{ count($headers) + 1 }}"
                    title="No meetings found"
                    :hasFilters="$hasFilters"
                >
                    @if(!$hasFilters)
                        <x-button link="{{ route('admin.meetings.create') }}" variant="primary" icon="plus" inline>
                            Create meeting
                        </x-button>
                    @endif
                </x-admin.empty-state>
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>
