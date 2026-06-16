@props([
    'icon' => 'inbox',
    'title' => 'No items found',
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center py-16 px-6 text-center']) }}>
    <div class="mb-6 flex h-24 w-24 items-center justify-center rounded-full bg-slate-100 text-slate-400">
        <x-dynamic-component :component="'heroicon-o-' . $icon" class="h-12 w-12" aria-hidden="true" />
    </div>

    <h2 class="mb-3 font-display text-3xl text-slate-900">{{ $title }}</h2>

    @if($description)
        <p class="mx-auto max-w-lg text-lg text-slate-600">
            {{ $description }}
        </p>
    @endif

    @if($slot->isNotEmpty())
        <div class="mt-8">
            {{ $slot }}
        </div>
    @endif
</div>
