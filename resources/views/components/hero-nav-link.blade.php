@props(['href', 'text'])

<p class="motion-safe:hover:scale-110 transition-transform duration-200">
  <a href="{{ $href }}" class="no-underline rounded-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-cbc-teal-dark" wire:navigate>
    <span class="px-2 py-1 bg-white text-2xl">{{ $text }}</span>
  </a>
</p>
