@props([
'sermon',
])

@php
    $sermonUrl = "/christ/sermons/{$sermon->date->format('Y')}/{$sermon->date->format('m')}/{$sermon->slug}";
@endphp

<div class="flex h-full max-w-sm flex-col overflow-hidden rounded-lg border border-gray-300 bg-white shadow-sm transition-shadow hover:shadow-md">

  @if($sermon->hasPlainThumbnail())
    <a href="{{ $sermonUrl }}" wire:navigate class="group relative block aspect-video overflow-hidden border-b border-gray-100 bg-slate-200">
      <img src="{{ route('serveSermonCardThumbnail', $sermon->slug) }}?v={{ md5($sermon->plain_thumbnail_file_path ?? '') }}" alt="Sermon: {{ $sermon->title }}" class="h-full w-full object-cover brightness-110 contrast-105 transition duration-500 ease-out group-hover:scale-105 group-hover:brightness-115" loading="lazy">
      <div class="absolute inset-0 bg-gradient-to-t from-black/35 via-black/10 to-transparent"></div>
      @if (($sermon->title != null))
        <h4 class="absolute inset-x-5 top-1/2 -translate-y-1/2 text-center font-display text-2xl leading-[0.95] text-white [text-shadow:0_2px_8px_rgba(0,0,0,0.45)] sm:text-3xl">
          {{$sermon->title}}
        </h4>
      @endif
    </a>
  @endif

  <div class="flex flex-col flex-1 p-6">
    @if (($sermon->title != null) && ! $sermon->hasPlainThumbnail())
      <a class="group" href="{{ $sermonUrl }}" wire:navigate>
        <h4 class="font-display text-2xl text-gray-900 group-hover:underline decoration-cbc-teal-light underline-offset-4">
          {{$sermon->title}}
        </h4>
      </a>
    @endif
    <ul class="mt-4 space-y-2 prose">
      @if (($sermon->date != null))
      <li class="flex items-center">
        <x-heroicon-s-calendar class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        {{ $sermon->date->format('j F Y') }}
      </li>
      @endif
      @if ($sermon->service != null)
      <li class="flex items-center">
        <x-heroicon-o-clock class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        {{ $sermon->service instanceof \App\Enums\SermonService ? $sermon->service->label() : \Illuminate\Support\Str::title($sermon->service) }}
      </li>
      @endif
      @if ($sermon->preacher != null)
      <li class="flex items-center">
        <x-heroicon-o-user class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        <a href="{{ $sermon->preacher_url }}" wire:navigate class="hover:text-cbc-teal-dark transition-colors">{{ $sermon->preacherProfile->name ?? $sermon->preacher }}</a>
      </li>
      @endif
      @if ($sermon->series != null)
      <li class="flex items-center">
        <x-heroicon-o-tag class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        <a href="/christ/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}" wire:navigate class="hover:text-cbc-teal-dark transition-colors">{{ $sermon->series }}</a>
      </li>
      @endif
      @if ($sermon->reference != null)
      <li class="flex items-center">
        <x-heroicon-o-book-open class="h-5 w-5 mr-2 text-gray-500" aria-hidden="true" />
        {{ $sermon->reference }}
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
      class="w-full justify-between rounded-none text-left font-normal"
      aria-label="View sermon: {{ $sermon->title }}"
  >
      View Sermon
  </x-button>

  @can ('manage-sermons')
    <div class="mt-auto border-t border-gray-100">
      <x-admin-actions
        :editRoute="'/christ/sermons/' . $sermon->date->format('Y') . '/' . $sermon->date->format('m') . '/' . $sermon->slug . '/edit'"
        :deleteRoute="'/christ/sermons/' . $sermon->date->format('Y') . '/' . $sermon->date->format('m') . '/' . $sermon->slug . '/delete'"
        deleteConfirmMessage="Are you sure you want to delete this sermon?"
        layout="grid"
        :withIcons="true" />
    </div>
  @endcan
</div>
