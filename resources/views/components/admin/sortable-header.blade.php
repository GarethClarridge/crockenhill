@props([
    'column',
    'label',
    'sortBy',
    'sortDirection',
    'sortable' => true,
])

<th {{ $attributes->merge(['class' => 'px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider']) }}
    @if($sortable) aria-sort="{{ $sortBy === $column ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}" @endif>
    @if($sortable)
        <button wire:click="sort('{{ $column }}')"
                wire:loading.attr="disabled"
                wire:loading.attr="aria-disabled"
                aria-disabled="false"
                wire:target="sort('{{ $column }}')"
                class="group inline-flex items-center gap-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded px-1 -mx-1 transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed"
                @if($sortBy === $column) aria-label="{{ $label }}: sorted {{ $sortDirection === 'asc' ? 'ascending' : 'descending' }}" @else aria-label="{{ $label }}: click to sort" @endif>
            <span>{{ $label }}</span>
            <span class="flex-none rounded bg-gray-100 text-gray-900 group-hover:bg-gray-200 transition-colors">
                <span wire:loading.remove wire:target="sort('{{ $column }}')">
                    @if($sortBy === $column)
                        @if($sortDirection === 'asc')
                            <x-heroicon-m-chevron-up class="h-3 w-3" aria-hidden="true" />
                        @else
                            <x-heroicon-m-chevron-down class="h-3 w-3" aria-hidden="true" />
                        @endif
                    @else
                        <x-heroicon-m-chevron-up-down class="h-3 w-3 opacity-0 group-hover:opacity-100 transition-opacity" aria-hidden="true" />
                    @endif
                </span>
                <span wire:loading wire:target="sort('{{ $column }}')" role="status" aria-live="polite">
                    <svg class="animate-spin h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span class="sr-only">Loading...</span>
                </span>
            </span>
        </button>
    @else
        {{ $label }}
    @endif
</th>
