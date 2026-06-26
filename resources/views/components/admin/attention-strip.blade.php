@props([
    /** list<array{label: string, count: int, href: string}> */
    'chips' => [],
])

@php
    $visibleChips = collect($chips)->filter(fn (array $chip): bool => ($chip['count'] ?? 0) > 0);
@endphp

<div {{ $attributes }}>
    @if($visibleChips->isEmpty())
        <p class="flex items-center gap-2 text-sm text-gray-500">
            <x-heroicon-o-check-circle class="h-5 w-5 text-cbc-teal" aria-hidden="true" />
            All caught up — nothing needs your attention.
        </p>
    @else
        <ul class="flex flex-wrap gap-2" aria-label="Items needing attention">
            @foreach($visibleChips as $chip)
                <li wire:key="attention-chip-{{ Str::slug($chip['label']) }}">
                    <a
                        href="{{ $chip['href'] }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1.5 text-sm font-medium text-amber-900 hover:bg-amber-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 transition-transform active:scale-95"
                    >
                        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-amber-200 px-1 text-xs font-semibold text-amber-900">{{ $chip['count'] }}</span>
                        {{ $chip['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
