<x-admin.form-shell
    :title="$isEditing ? 'Edit service' : 'Create service'"
    :description="$isEditing ? 'Update the canonical order of service for this date and slot' : 'Create a service manually when email or OpenLP is not available'"
    save-action="save"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
            Back to services
        </x-button>
        @if($isEditing && $churchService)
            <x-button link="{{ route('admin.services.show', $churchService) }}" variant="outline" icon="eye" inline>
                View service
            </x-button>
        @endif
        <x-form-button variant="primary" wire:click="save" icon="check">
            {{ $isEditing ? 'Save service' : 'Create service' }}
        </x-form-button>
    </x-slot:actions>

    <x-card heading="Service details">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-input
                label="Date"
                type="date"
                wire:model.live="form.date"
                required />

            <x-select
                label="Service"
                wire:model.live="form.service"
                :options="$serviceOptions"
                placeholder="Choose a service"
                required />
        </div>
    </x-card>

    <x-card heading="Service items">
        <div class="space-y-4">
            @error('form.items')
                <p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                    {{ $message }}
                </p>
            @enderror

            @foreach($items as $index => $item)
                <div wire:key="{{ $item['key'] }}"
                    x-data="{ visible: false }"
                    x-init="$nextTick(() => visible = true)"
                    x-show="visible"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-4"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="rounded-lg border border-gray-200 p-4">
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
                                    class="overflow-hidden rounded-md border border-gray-200">
                                    <div class="divide-y divide-gray-200 bg-white">
                                        @foreach($songSuggestions[$index] as $suggestion)
                                            <button
                                                type="button"
                                                wire:click="selectSong({{ $index }}, {{ $suggestion['id'] }})"
                                                class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal transition-all active:scale-[0.98]">
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
                                <p class="text-sm text-cbc-teal-dark">
                                    Linked song selected.
                                </p>
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
    </x-card>

    <x-slot:sidebar>
        <x-card heading="Guidance">
            <div class="space-y-3 text-sm text-gray-600">
                <p>Use the service type dropdown to capture the planned structure, not just the spoken title.</p>
                <p>Songs can be saved as free text, but linking them to the catalogue improves later matching and reporting.</p>
                <p>Manual saves become the canonical reviewed service list and clear any existing review flag.</p>
            </div>
        </x-card>

        @if($isEditing && $churchService)
            <x-card heading="Current source">
                <div class="space-y-2 text-sm text-gray-600">
                    <p><span class="font-medium text-gray-900">Source:</span> {{ strtoupper($churchService->source) }}</p>
                    <p><span class="font-medium text-gray-900">Original filename:</span> {{ $churchService->original_filename ?: 'None' }}</p>
                    <p><span class="font-medium text-gray-900">Review flag:</span> {{ $churchService->needs_review ? 'Needs review' : 'Clear' }}</p>
                </div>
            </x-card>
        @endif
    </x-slot:sidebar>
</x-admin.form-shell>
