@props([
    'heading',
])

<div class="relative max-w-full flex-1 px-4 mb-4">
    <div class="rounded-lg shadow bg-white border-1 border-gray-300 p-0 m-2">
        
        <div class="p-6">
            @isset($heading)
                <h3 class="mb-3 font-display text-2xl">
                    {{ $heading }}
                </h3>
            @endisset
            <div class="prose">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>