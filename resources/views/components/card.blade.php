@props([
    'heading' => null,
    'prose' => false,
])

<div {{ $attributes->merge(['class' => 'rounded-lg shadow bg-white border border-gray-300']) }}>
    <div class="p-6">
        @isset($heading)
            <h2 class="mb-3 font-display text-2xl">
                {{ $heading }}
            </h2>
        @endisset
        <div @class(['prose max-w-none' => $prose])>
            {{ $slot }}
        </div>
    </div>
    @isset($footer)
        <div class="px-4 sm:px-6 py-4 bg-gray-50 border-t border-gray-200 rounded-b-lg">
            {{ $footer }}
        </div>
    @endisset
</div>
