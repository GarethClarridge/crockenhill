@props([
    'title',
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'space-y-6']) }}>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl text-gray-900">{{ $title }}</h1>
            @if($description)
                <p class="text-gray-600">{{ $description }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="flex shrink-0 items-center gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>

    {{ $slot }}
</div>
