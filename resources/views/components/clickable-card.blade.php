@props([
    'link',
    'heading' => null,
])

@php
$isInternalLink = !str_starts_with($link, 'http') && !str_ends_with($link, '.pdf');
@endphp

<a href="{{ $link }}" @if($isInternalLink) wire:navigate @endif class="group block max-w-sm p-6 bg-white border border-gray-200 rounded-lg shadow transition-all duration-300 hover:bg-gray-50 hover:-translate-y-1 hover:shadow-lg hover:border-cbc-teal/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2">
    <div class="flex items-center justify-between gap-4 {{ $slot->isEmpty() ? '' : 'mb-3' }}">
        <h5 class="text-2xl font-display font-semibold text-cbc-teal-dark group-hover:text-cbc-teal transition-colors">
            {{ $heading ?? $slot }}
        </h5>
        <x-heroicon-o-chevron-right class="w-5 h-5 text-gray-400 group-hover:text-cbc-teal group-hover:translate-x-1 transition-all shrink-0" aria-hidden="true" />
    </div>
    @if($heading && $slot->isNotEmpty())
        <div class="prose p-0 font-normal text-slate-600">
            {{ $slot }}
        </div>
    @endif
</a>
