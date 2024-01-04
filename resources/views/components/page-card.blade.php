@props([
'page',
])

<div class="relative max-w-full flex-1 px-4 mb-4">
  <div class="rounded-lg shadow bg-white border-1 border-gray-300 p-0 m-2">
    <div class="relative overflow-hidden">
      @if ($page->area == 'sermons')
      <a href="/christ/sermons/{{$page->slug}}">
        @else
        <a href="/{{$page->area}}/{{$page->slug}}">
          @endif
          <img class="w-full rounded-t-lg brightness-75 contrast-75 hover:scale-110 transition-all duration-500" src="/images/headings/small/{{$page->slug}}.jpg" alt="" onerror="this.onerror=null;this.src='/images/headings/small/default.jpg';">
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
      <x-button link="/christ/sermons/{{$page->slug}}">
        <div class="flex items-center justify-center">
          Find out more
          <x-heroicon-s-arrow-right-circle class="h-6 w-6 ml-2" />
        </div>
      </x-button>
      @else
      <x-button link="/{{$page->area}}/{{$page->slug}}">
        <div class="flex items-center justify-center">
          Find out more
          <x-heroicon-s-arrow-right-circle class="h-6 w-6 ml-2" />
        </div>
      </x-button>
      @endif

      <x-edit-buttons slug="{{$page->slug}}" />

    </div>
  </div>
</div>