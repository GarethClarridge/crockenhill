<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="font-display text-3xl">Meetings</h1>
            <p class="text-gray-600">Manage church meetings and events</p>
        </div>
        <x-button link="{{ route('admin.meetings.create') }}" variant="primary" icon="plus" inline>
            Create Meeting
        </x-button>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4">
        <x-input placeholder="Search meetings..." wire:model.live.debounce="search" icon="magnifying-glass" clearable class="w-64" />

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
                                @if($header['sortable'])
                                    <button wire:click="sort('{{ $header['key'] }}')" class="group inline-flex items-center gap-1 focus:outline-none">
                                        {{ $header['label'] }}
                                        <span class="flex-none rounded bg-gray-100 text-gray-900 group-hover:bg-gray-200">
                                            @if($sortBy === $header['key'])
                                                @if($sortDirection === 'asc')
                                                    <x-heroicon-m-chevron-up class="h-3 w-3" aria-hidden="true" />
                                                @else
                                                    <x-heroicon-m-chevron-down class="h-3 w-3" aria-hidden="true" />
                                                @endif
                                            @else
                                                <x-heroicon-m-chevron-up-down class="h-3 w-3 opacity-0 group-hover:opacity-100 transition-opacity" aria-hidden="true" />
                                            @endif
                                        </span>
                                    </button>
                                @else
                                    {{ $header['label'] }}
                                @endif
                            </th>
                        @endforeach
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($meetings as $meeting)
                        <tr class="hover:bg-gray-50">
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
                                        {{ $meeting->start_time->format('H:i') }}
                                        @if($meeting->end_time)
                                            - {{ $meeting->end_time->format('H:i') }}
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
                                <div class="flex gap-1 justify-end">
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
                        <tr>
                            <td colspan="{{ count($headers) + 1 }}" class="px-4 py-8 text-center text-gray-500">
                                No meetings found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $meetings->links() }}
        </div>
    </x-card>
</div>
