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
                class="group inline-flex items-center gap-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded px-1 -mx-1 transition-all"
                @if($sortBy === $column) aria-label="{{ $label }}: sorted {{ $sortDirection === 'asc' ? 'ascending' : 'descending' }}" @else aria-label="{{ $label }}: click to sort" @endif>
            <span>{{ $label }}</span>
            <span class="flex-none rounded bg-gray-100 text-gray-900 group-hover:bg-gray-200 transition-colors">
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
        </button>
    @else
        {{ $label }}
    @endif
</th>
