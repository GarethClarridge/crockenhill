@props([
'page',
])

@if ($page)
<div class="rounded-lg shadow bg-white border-1 border-gray-300 mb-4">
  <div class="relative overflow-hidden bg-slate-200 aspect-video">
      @if ($page->area == 'sermons')
      <a href="/christ/sermons/{{$page->slug}}" wire:navigate>
        @else
        <a href="/{{$page->area}}/{{$page->slug}}" wire:navigate>
          @endif
          <img class="w-full h-full rounded-t-lg object-cover brightness-75 contrast-75 hover:scale-110 transition-all duration-500" src="{{ $page->heading_image_small_url ?? '/images/headings/small/default.webp' }}" alt="{{ $page->heading }}" onerror="this.onerror=null;this.src='/images/headings/small/default.webp';" loading="lazy" width="300" height="169">
          <h5 class="leading-normal align-middle absolute top-1/3 left-0 right-0 text-white font-display text-2xl text-center">
            {{ $page->heading }}
          </h5>
        </a>
    </div>

    <div class="p-6 prose text-center">
      <p>
        {{ $page->description }}
      </p>

      @if ($page->area == 'sermons')
      <x-button link="/christ/sermons/{{$page->slug}}" size="sm">
        <div class="flex items-center justify-center">
          Learn about {{ $page->heading }}
          <x-heroicon-s-arrow-right-circle class="h-6 w-6 ml-2" />
        </div>
      </x-button>
      @else
      <x-button link="/{{$page->area}}/{{$page->slug}}" size="sm">
        <div class="flex items-center justify-center">
          Learn about {{ $page->heading }}
          <x-heroicon-s-arrow-right-circle class="h-6 w-6 ml-2" />
        </div>
      </x-button>
      @endif

      <x-edit-buttons slug="{{$page->slug}}" />

    </div>
</div>
@endif
