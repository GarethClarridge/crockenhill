<div class="pb-12">
    <a href="#sermon-results" class="sr-only focus:not-sr-only focus:absolute focus:z-30 focus:m-4 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-cbc-teal-dark focus:shadow-lg focus:outline-none focus:ring-2 focus:ring-cbc-teal">
        Skip to results
    </a>

    <section
        class="px-6"
        x-data="{ expanded: @js($hasActiveFilters) }"
        @keydown.escape.window="expanded = false"
        @click.away="expanded = false"
    >
        <div class="mx-auto max-w-2xl lg:max-w-5xl xl:max-w-7xl">
            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-center">
                <x-form-button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="w-full justify-center sm:w-auto"
                    @click="expanded = !expanded"
                    ::aria-expanded="expanded.toString()"
                    aria-controls="sermon-filters"
                >
                    <span class="inline-flex items-center gap-2">
                        <x-heroicon-o-adjustments-horizontal class="h-4 w-4" aria-hidden="true" />
                        <span x-text="expanded ? 'Hide filters' : 'Filter sermons'">Filter sermons</span>
                        <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform duration-200" x-bind:class="expanded ? 'rotate-180' : ''" aria-hidden="true" />
                    </span>
                </x-form-button>

                @if ($hasActiveFilters)
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        <span class="text-sm text-gray-500">Filtered by</span>

                        @foreach ($activeFilterLabels as $key => $label)
                            <x-badge variant="teal">
                                {{ $label }}
                                <button
                                    type="button"
                                    wire:click="removeFilter('{{ $key }}')"
                                    class="ml-1 -mr-1 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-cbc-teal hover:bg-cbc-teal/20 hover:text-cbc-teal-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal"
                                    aria-label="Remove filter: {{ $label }}"
                                    title="Remove filter: {{ $label }}"
                                >
                                    <x-heroicon-o-x-mark class="h-3 w-3" />
                                </button>
                            </x-badge>
                        @endforeach
                    </div>

                    <x-form-button type="button" variant="ghost" size="sm" icon="x-mark" wire:click="clearFilters">
                        Clear filters
                    </x-form-button>
                @endif
            </div>
        </div>

        <div
            id="sermon-filters"
            x-show="expanded"
            x-cloak
            x-collapse
            class="mx-auto mt-4 max-w-2xl rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5 lg:max-w-5xl xl:max-w-7xl"
        >
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <x-select
                    label="Book"
                    wire:model.live="bookFilter"
                    placeholder="All books"
                    :options="$bookOptions"
                    class="w-full"
                />

                <x-select
                    label="Chapter"
                    wire:model.live="chapterFilter"
                    placeholder="{{ $bookFilter === null ? 'Choose a book first' : 'All chapters' }}"
                    :options="$chapterOptions"
                    :disabled="$bookFilter === null"
                    class="w-full"
                />

                <x-select
                    label="Preacher"
                    wire:model.live="preacherFilter"
                    placeholder="All preachers"
                    :options="$preacherOptions"
                    class="w-full"
                />

                <x-select
                    label="Series"
                    wire:model.live="seriesFilter"
                    placeholder="All series"
                    :options="$seriesOptions"
                    class="w-full"
                />
            </div>
        </div>
    </section>

    <div wire:loading.flex wire:target="bookFilter, chapterFilter, preacherFilter, seriesFilter, removeFilter, clearFilters" class="mx-auto mt-4 max-w-7xl items-center gap-2 px-6 text-sm text-gray-500" role="status">
        <svg class="h-4 w-4 animate-spin text-cbc-teal" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        Updating sermon results…
    </div>

    <div id="sermon-results" wire:loading.class="pointer-events-none opacity-60" wire:target="bookFilter, chapterFilter, preacherFilter, seriesFilter, removeFilter, clearFilters">
        @php
            /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Sermon> $sermons */
        @endphp

        @if ($sermons->total() > 0)
            <div class="mx-auto mt-4 max-w-7xl px-6 text-sm text-gray-500" aria-live="polite">
                @if ($sermons->hasPages())
                    Showing <span class="font-medium text-gray-700">{{ $sermons->firstItem() }}</span> to <span class="font-medium text-gray-700">{{ $sermons->lastItem() }}</span> of <span class="font-medium text-gray-700">{{ $sermons->total() }}</span> sermons
                @else
                    Showing <span class="font-medium text-gray-700">{{ $sermons->total() }}</span> {{ Str::plural('sermon', $sermons->total()) }}
                @endif
            </div>

            <div class="mx-auto mt-6 mb-6 grid max-w-2xl items-start justify-center gap-4 px-6 [grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),19rem))] lg:max-w-5xl xl:max-w-7xl">
                @foreach ($sermons as $sermon)
                    <div wire:key="sermon-{{ $sermon->id }}">
                        <x-sermon-card :sermon="$sermon" />
                    </div>
                @endforeach
            </div>

            @if ($sermons->hasPages())
                <div class="mx-auto mt-8 max-w-2xl px-6">
                    {{ $sermons->links(data: ['scrollTo' => '#sermon-results']) }}
                </div>
            @endif
        @else
            <section class="mx-auto mt-8 max-w-2xl px-6">
                @if ($hasActiveFilters)
                    <x-card heading="No sermons match these filters">
                        <p class="text-gray-600">
                            Try another book, chapter, preacher, or series, or clear the filters to return to the full sermon archive.
                        </p>

                        <div class="mt-4">
                            <x-form-button type="button" variant="outline" size="sm" icon="x-mark" wire:click="clearFilters">
                                Clear filters
                            </x-form-button>
                        </div>
                    </x-card>
                @else
                    <x-card heading="No sermons published yet">
                        <p class="text-gray-600">
                            Sermons will appear here once they have been added to the public archive.
                        </p>
                    </x-card>
                @endif
            </section>
        @endif
    </div>
</div>
