@props([
    'link',
    'heading',
])

<a href="{{ $link }}" class="block max-w-sm p-6 bg-white border border-gray-200 rounded-lg shadow hover:bg-gray-100">
    <h5 class="mb-2 text-2x">
        {{ $heading }}
    </h5>
    <p class="prose p-0 font-normal">
        {{ $slot }}
    </p>
</a>
  