@props(['quiet' => false])

{{-- Shown beside a song clip recorded partly very quietly (register E25: set by the pipeline or in the song admin). --}}
@if ($quiet)
    <p {{ $attributes->merge(['class' => 'flex items-start gap-2 text-sm text-gray-600']) }}>
        <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 flex-shrink-0 text-cbc-teal" aria-hidden="true" />
        <span>Part of this song was recorded very quietly.</span>
    </p>
@endif
