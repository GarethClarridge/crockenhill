@props([
    'sermon',
    'sermonView' => null,
])

@php
    if ($sermonView === null) {
        $sermonView = app(\App\Presenters\SermonViewPresenter::class)->presentForList($sermon);
    }
    $sermonUrl = $sermonView['canonical_url'];
    $thumbnailUrl = $sermonView['plain_thumbnail_url'];
    $preacherName = $sermonView['preacher_name'];
    $reference = $sermonView['display_reference'];
    $preacherUrl = $sermonView['preacher_url'];
    $formattedDuration = $sermonView['formatted_duration'];
    $seriesUrl = $sermonView['series_url'];
    $serviceLabel = $sermonView['service_label'];
    $dateString = $sermonView['date_string'];
@endphp

<div data-sermon-card class="group relative flex h-full max-w-sm flex-col overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">

  @if($thumbnailUrl)
    <a
      href="{{ $sermonUrl }}"
      wire:navigate
      data-sermon-card-thumbnail
      class="relative z-10 block aspect-video overflow-hidden border-b border-gray-100 bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
    >
      <img
        src="{{ $thumbnailUrl }}"
        alt="Sermon: {{ $sermon->title }}"
        class="h-full w-full object-cover brightness-110 contrast-105 transition duration-500 ease-out group-hover:scale-105 group-hover:brightness-115"
        loading="lazy"
        onerror="this.onerror=null; const card = this.closest('[data-sermon-card]'); card?.querySelector('[data-sermon-card-thumbnail]')?.remove(); card?.querySelector('[data-sermon-card-title-fallback]')?.classList.remove('hidden');"
      >
      <div class="absolute inset-0 bg-gradient-to-t from-black/35 via-black/10 to-transparent"></div>
      @if (($sermon->title != null))
        <h2 class="absolute inset-x-5 top-1/2 -translate-y-1/2 text-center font-display text-2xl leading-[0.95] text-white [text-shadow:0_2px_8px_rgba(0,0,0,0.45)] sm:text-3xl">
          {{$sermon->title}}
        </h2>
      @endif
    </a>
  @endif

  <div class="flex flex-col flex-1 p-6">
    @if (($sermon->title != null) && ! $thumbnailUrl)
      <a class="relative z-10 group rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2" href="{{ $sermonUrl }}" wire:navigate aria-label="{{ $sermon->title }}">
        <h2 class="font-display text-2xl text-gray-900 group-hover:underline decoration-cbc-teal-light underline-offset-4">
          {{$sermon->title}}
        </h2>
      </a>
    @elseif ($sermon->title != null)
      <a class="relative z-10 group hidden rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2" href="{{ $sermonUrl }}" wire:navigate data-sermon-card-title-fallback aria-label="{{ $sermon->title }}">
        <h2 class="font-display text-2xl text-gray-900 group-hover:underline decoration-cbc-teal-light underline-offset-4">
          {{$sermon->title}}
        </h2>
      </a>
    @endif
    <ul class="mt-4 space-y-2 prose">
      @if ($dateString)
      <li class="flex items-center">
        <x-heroicon-s-calendar class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        <time datetime="{{ $sermon->date->toDateString() }}">
          {{ $dateString }}
        </time>
      </li>
      @endif
      @if ($serviceLabel)
      <li class="flex items-center">
        <x-heroicon-o-clock class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        <span class="flex-1">
          {{ $serviceLabel }}
        </span>
        @if ($formattedDuration)
          <span class="flex items-center text-xs font-medium text-gray-500 bg-gray-50 px-2 py-0.5 rounded-full border border-gray-100 ml-2" title="Sermon duration">
            <x-heroicon-o-play-circle class="h-3.5 w-3.5 mr-1" aria-hidden="true" />
            <span class="sr-only">Duration: </span>
            {{ $formattedDuration }}
          </span>
        @endif
      </li>
      @endif
      @if ($preacherName != null)
      <li class="flex items-center">
        <x-heroicon-o-user class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        @if ($preacherUrl)
          <a href="{{ $preacherUrl }}" wire:navigate class="relative z-10 hover:text-cbc-teal-dark transition-colors">{{ $preacherName }}</a>
        @else
          <span>{{ $preacherName }}</span>
        @endif
      </li>
      @endif
      @if ($sermon->series != null)
      <li class="flex items-center">
        <x-heroicon-o-tag class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        <a href="{{ $seriesUrl }}" wire:navigate class="relative z-10 hover:text-cbc-teal-dark transition-colors">{{ $sermon->series }}</a>
      </li>
      @endif
      @if ($reference != null)
      <li class="flex items-center">
        <x-heroicon-o-book-open class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        {{ $reference }}
      </li>
      @endif
    </ul>
  </div>

  <x-button
      :link="$sermonUrl"
      variant="feature"
      size="card"
      icon="arrow-right-circle"
      iconStyle="solid"
      iconPosition="trailing"
      iconClass="shrink-0 text-white/90"
      class="w-full justify-between rounded-none text-left font-normal after:absolute after:inset-0"
      aria-label="View sermon: {{ $sermon->title }}"
  >
      View Sermon
  </x-button>

  <div class="relative z-10">
    <x-sermon-card-admin-overlay :$sermon />
  </div>
</div>
