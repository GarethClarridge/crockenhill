@props(['href', 'text'])

<p class="hover:scale-110 my-4">
  <a href="{{ $href }}" wire:navigate>
    <span class="px-2 py-1 bg-white">{{ $text }}</span>
  </a>
</p>