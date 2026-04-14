<div class="pb-12">
    <section class="mx-auto max-w-7xl px-6">
        <div class="overflow-hidden rounded-2xl border border-gray-300 bg-white shadow-sm">
            <div class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                <div class="space-y-2">
                    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cbc-teal-dark">Sermon Browse</p>
                    <div class="space-y-2">
                        <h2 class="font-display text-3xl text-gray-900 sm:text-4xl">Browse by scripture, preacher, or series</h2>
                        <p class="max-w-3xl text-base text-gray-600">
                            Keep browsing by date, or narrow the list to a particular Bible book, chapter, preacher, or sermon series.
                        </p>
                    </div>
                </div>

                @if ($hasActiveFilters)
                    <div class="flex justify-start lg:justify-end">
                        <x-form-button type="button" variant="outline" size="sm" icon="x-mark" wire:click="clearFilters">
                            Clear filters
                        </x-form-button>
                    </div>
                @endif
            </div>

            <div class="grid gap-4 border-t border-gray-200 p-6 md:grid-cols-2 xl:grid-cols-4">
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

    <div wire:loading.flex wire:target="bookFilter, chapterFilter, preacherFilter, seriesFilter" class="mx-auto mt-4 max-w-7xl items-center gap-2 px-6 text-sm text-gray-500">
        <svg class="h-4 w-4 animate-spin text-cbc-teal" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        Updating sermon results…
    </div>

    <div wire:loading.class="pointer-events-none opacity-60" wire:target="bookFilter, chapterFilter, preacherFilter, seriesFilter">
        @if ($hasActiveFilters)
            @php
                /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \App\Models\Sermon> $sermons */
            @endphp

            @if ($sermons->total() > 0)
                <div class="mx-auto mt-6 max-w-7xl px-6 text-sm text-gray-600">
                    {{ $sermons->total() }} sermon{{ $sermons->total() === 1 ? '' : 's' }} found
                </div>

                <div class="mx-auto mt-4 mb-6 grid max-w-2xl items-start justify-center gap-2 px-6 [grid-template-columns:repeat(auto-fit,minmax(min(100%,19rem),19rem))] lg:max-w-5xl xl:max-w-7xl">
                    @foreach ($sermons as $sermon)
                        <div wire:key="filtered-sermon-{{ $sermon->id }}">
                            <x-sermon-card :sermon="$sermon" />
                        </div>
                    @endforeach
                </div>

                @if ($sermons->hasPages())
                    <div class="mx-auto mt-8 max-w-2xl px-6">
                        {{ $sermons->links() }}
                    </div>
                @endif
            @else
                <section class="mx-auto mt-8 max-w-2xl px-6">
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
                </section>
            @endif
        @else
            @if ($sermons->isNotEmpty())
                <x-sermon-list :sermons="$sermons" :groupedByDate="true" />
            @else
                <section class="mx-auto mt-8 max-w-2xl px-6">
                    <x-card heading="No sermons published yet">
                        <p class="text-gray-600">
                            Sermons will appear here once they have been added to the public archive.
                        </p>
                    </x-card>
                </section>
            @endif
        @endif
    </div>
</div>
