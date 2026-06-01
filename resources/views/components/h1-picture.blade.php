@props(['headingpicture', 'headingpictureMobile' => null, 'headingpictureTablet' => null])

<div class="flex items-center justify-center overflow-hidden h-[33vh] relative">
  {{-- Background overlay --}}
  <div class="absolute inset-0 bg-black/40 z-10"></div>

  {{-- Responsive background image using picture element --}}
  <picture class="absolute inset-0 w-full h-full">
    @if($headingpictureMobile)
      <source media="(max-width: 640px)" srcset="{{ $headingpictureMobile }}">
    @endif
    @if($headingpictureTablet)
      <source media="(max-width: 1024px)" srcset="{{ $headingpictureTablet }}">
    @endif
    <img
      src="{{ $headingpicture }}"
      alt=""
      role="presentation"
      class="w-full h-full object-cover"
      loading="eager"
      fetchpriority="high"
    >
  </picture>

  <h1 class="text-white font-display text-6xl relative z-20">
    {{ $slot }}
  </h1>
</div>