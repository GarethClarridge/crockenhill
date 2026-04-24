@props([
'area',
'heading',
'jsonOnly' => false,
])

<script type="application/ld+json">
  {!! json_encode($breadcrumbList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>

@if(!$jsonOnly)
<div class="my-6 flex flex-wrap items-center justify-between gap-4">
  <nav aria-label="Breadcrumb">
    <ol class="inline-flex flex-wrap items-center space-x-1 md:space-x-2">
      @foreach ($breadcrumbItems as $index => $item)
    @if ($index === 0)
    {{-- Home item with icon --}}
    <li class="inline-flex items-center">
      <a href="{{ $item['item'] }}" wire:navigate class="inline-flex items-center">
        <svg class="w-3 h-3 me-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
          <path d="m19.707 9.293-2-2-7-7a1 1 0 0 0-1.414 0l-7 7-2 2a1 1 0 0 0 1.414 1.414L2 10.414V18a2 2 0 0 0 2 2h3a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h3a2 2 0 0 0 2-2v-7.586l.293.293a1 1 0 0 0 1.414-1.414Z" />
        </svg>
        {{ $item['name'] }}
      </a>
    </li>
    @elseif ($index === count($breadcrumbItems) - 1)
    {{-- Current page (last item) --}}
    <li aria-current="page" class="flex items-center">
      <svg class="w-3 h-3 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4" />
      </svg>
      <span class="ms-1 md:ms-2">
        {{ $item['name'] }}
      </span>
    </li>
    @else
    {{-- Middle items --}}
    <li class="flex items-center">
      <svg class="w-3 h-3 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10">
        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4" />
      </svg>
      <a href="{{ $item['item'] }}" wire:navigate class="ms-1 md:ms-2">
        {{ $item['name'] }}
      </a>
    </li>
    @endif
      @endforeach
    </ol>
  </nav>

  <x-clipboard-button :url="url()->current()" />
</div>
@endif
