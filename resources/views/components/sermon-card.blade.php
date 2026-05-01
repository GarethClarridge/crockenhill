@props([
'sermon',
'audio_url',
'canonical_url',
'card_thumbnail_url',
'display_reference',
'duration_iso8601',
'formatted_duration',
'has_transcript',
'human_date',
'preacher_image_url',
'preacher_name',
'preacher_url',
'public_url',
'series_url',
'thumbnail_url',
'plain_thumbnail_url',
'transcript_url',
'video_url',
])

<div data-sermon-card class="group relative flex h-full max-w-sm flex-col overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">

  @if($plain_thumbnail_url)
    <a
      href="{{ $canonical_url }}"
      wire:navigate
      tabindex="-1"
      aria-hidden="true"
      data-sermon-card-thumbnail
      class="relative block aspect-video overflow-hidden border-b border-gray-100 bg-slate-200"
    >
      <img
        src="{{ $plain_thumbnail_url }}"
        alt="Sermon: {{ $sermon->title }}"
        class="h-full w-full object-cover brightness-110 contrast-105 transition duration-500 ease-out group-hover:scale-105 group-hover:brightness-115"
        loading="lazy"
        onerror="this.onerror=null; const card = this.closest('[data-sermon-card]'); card?.querySelector('[data-sermon-card-thumbnail]')?.remove(); card?.querySelector('[data-sermon-card-title-fallback]')?.classList.remove('hidden');"
      >
      <div class="absolute inset-0 bg-gradient-to-t from-black/35 via-black/10 to-transparent"></div>
      @if (($sermon->title != null))
        <h4 class="absolute inset-x-5 top-1/2 -translate-y-1/2 text-center font-display text-2xl leading-[0.95] text-white [text-shadow:0_2px_8px_rgba(0,0,0,0.45)] sm:text-3xl">
          {{$sermon->title}}
        </h4>
      @endif
    </a>
  @endif

  <div class="flex flex-col flex-1 p-6">
    @if (($sermon->title != null) && ! $plain_thumbnail_url)
      <a class="group" href="{{ $canonical_url }}" wire:navigate tabindex="-1" aria-hidden="true">
        <h4 class="font-display text-2xl text-gray-900 group-hover:underline decoration-cbc-teal-light underline-offset-4">
          {{$sermon->title}}
        </h4>
      </a>
    @elseif ($sermon->title != null)
      <a class="group hidden" href="{{ $canonical_url }}" wire:navigate data-sermon-card-title-fallback>
        <h4 class="font-display text-2xl text-gray-900 group-hover:underline decoration-cbc-teal-light underline-offset-4">
          {{$sermon->title}}
        </h4>
      </a>
    @endif
    <ul class="mt-4 space-y-2 prose">
      @if (($sermon->date != null))
      <li class="flex items-center">
        <x-heroicon-s-calendar class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        <time datetime="{{ $sermon->date->toDateString() }}">
          {{ $human_date }}
        </time>
      </li>
      @endif
      @if ($sermon->service != null)
      <li class="flex items-center">
        <x-heroicon-o-clock class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        <span class="flex-1">
          {{ $sermon->service instanceof \App\Enums\SermonService ? $sermon->service->label() : \Illuminate\Support\Str::title($sermon->service) }}
        </span>
        @if ($formatted_duration)
          <span class="flex items-center text-xs font-medium text-gray-400 bg-gray-50 px-2 py-0.5 rounded-full border border-gray-100 ml-2" title="Sermon duration">
            <x-heroicon-o-play-circle class="h-3.5 w-3.5 mr-1" aria-hidden="true" />
            {{ $formatted_duration }}
          </span>
        @endif
      </li>
      @endif
      @if ($preacher_name != null)
      <li class="flex items-center">
        <x-heroicon-o-user class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        @if ($preacher_url)
          <a href="{{ $preacher_url }}" wire:navigate class="relative z-10 hover:text-cbc-teal-dark transition-colors">{{ $preacher_name }}</a>
        @else
          <span>{{ $preacher_name }}</span>
        @endif
      </li>
      @endif
      @if ($sermon->series != null)
      <li class="flex items-center">
        <x-heroicon-o-tag class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        <a href="{{ $series_url }}" wire:navigate class="relative z-10 hover:text-cbc-teal-dark transition-colors">{{ $sermon->series }}</a>
      </li>
      @endif
      @if ($display_reference != null)
      <li class="flex items-center">
        <x-heroicon-o-book-open class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        {{ $display_reference }}
      </li>
      @endif
    </ul>
  </div>

  <x-button
      :link="$canonical_url"
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
