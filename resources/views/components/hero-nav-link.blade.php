@props(['href', 'text'])

<p class="hover:scale-110">
  <a href="{{ $href }}" class="no-underline" wire:navigate>
    <span class="px-2 py-1 bg-white text-2xl">{{ $text }}</span>
  </a>
</p>