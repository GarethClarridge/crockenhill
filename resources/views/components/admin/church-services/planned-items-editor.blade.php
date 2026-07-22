@props([
    'items',
    'sectionTypeOptions',
    'songSuggestions',
])

<div class="space-y-4">
    @error('form.items')
        <p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
            {{ $message }}
        </p>
    @enderror

    @foreach($items as $index => $item)
        <div
            wire:key="{{ $item['key'] }}"
            x-data="{ visible: false }"
            x-init="$nextTick(() => visible = true)"
            x-show="visible"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="rounded-lg border border-gray-200 p-4"
        >
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-2 text-sm font-medium text-gray-500">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-700">
                        {{ $index + 1 }}
                    </span>
                    <span>Item {{ $index + 1 }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <x-form-button
                        type="button"
                        variant="ghost"
                        size="xs"
                        icon="arrow-up"
                        wire:click="moveItemUp({{ $index }})"
                        aria-label="Move item {{ $index + 1 }} up" />

                    <x-form-button
                        type="button"
                        variant="ghost"
                        size="xs"
                        icon="arrow-down"
                        wire:click="moveItemDown({{ $index }})"
                        aria-label="Move item {{ $index + 1 }} down" />

                    <x-form-button
                        type="button"
                        variant="danger"
                        size="xs"
                        icon="trash"
                        wire:click="removeItem({{ $index }})"
                        wire:confirm="Remove this service item?"
                        aria-label="Remove item {{ $index + 1 }}" />
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-5">
                <div class="md:col-span-2">
                    <x-select
                        label="Type"
                        wire:model.live="form.items.{{ $index }}.section_type"
                        :options="$sectionTypeOptions"
                        required />
                </div>

                <div class="md:col-span-3">
                    <x-input
                        label="{{ $item['section_type'] === \App\Enums\ServiceSectionType::Song->value ? 'Song title' : 'Title' }}"
                        wire:model.live.debounce.300ms="form.items.{{ $index }}.title"
                        required />
                </div>
            </div>

            @if($item['section_type'] === \App\Enums\ServiceSectionType::Song->value)
                <div class="mt-4 space-y-3">
                    <p class="text-sm text-gray-500">Search the song catalogue to link this item to a canonical song.</p>

                    @if(($songSuggestions[$index] ?? []) !== [])
                        <div
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-2"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            class="overflow-hidden rounded-md border border-gray-200"
                        >
                            <div class="divide-y divide-gray-200 bg-white">
                                @foreach($songSuggestions[$index] as $suggestion)
                                    <button
                                        type="button"
                                        wire:click="selectSong({{ $index }}, {{ $suggestion['id'] }})"
                                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm transition-all hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal active:scale-[0.98]"
                                    >
                                        <span>{{ $suggestion['title'] }}</span>
                                        <span class="text-xs text-gray-500">Use song</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @elseif(mb_strlen(trim((string) $item['title'])) >= 2)
                        <p class="rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-500">
                            No matching songs found. You can still save the item with a free-text title.
                        </p>
                    @endif

                    @if($item['song_id'])
                        <p class="text-sm text-cbc-teal-dark">Linked song selected.</p>
                    @endif
                </div>
            @endif
        </div>
    @endforeach

    <div class="flex justify-start">
        <x-form-button type="button" variant="outline" wire:click="addItem" icon="plus" size="sm">
            Add item
        </x-form-button>
    </div>
</div>
