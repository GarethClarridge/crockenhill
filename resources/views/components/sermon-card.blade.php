@props([
'sermon',
])

<div class="max-w-sm rounded-lg shadow-sm bg-white border border-gray-300 flex flex-col overflow-hidden transition-shadow hover:shadow-md">

  @if($sermon->hasThumbnail())
    <a href="/christ/sermons/{{ $sermon->date->format('Y') }}/{{ $sermon->date->format('m') }}/{{ $sermon->slug }}" wire:navigate class="block aspect-video overflow-hidden border-b border-gray-100">
      <img src="{{ route('serveSermonThumbnail', $sermon->slug) }}" alt="Sermon: {{ $sermon->title }}" class="h-full w-full object-cover transition-transform duration-300 hover:scale-105" loading="lazy">
    </a>
  @endif

  <div class="flex flex-col flex-1 p-6">
    @if($sermon->isFromLivestream())
      <div class="mb-4">
        <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">
          <span class="mr-1 h-2 w-2 rounded-full bg-red-600 animate-pulse"></span>
          Livestream
        </span>
      </div>
    @endif

    @if (($sermon->title != null))
      <a class="group" href="/christ/sermons/{{ $sermon->date->format('Y') }}/{{ $sermon->date->format('m') }}/{{ $sermon->slug }}" wire:navigate>
        <h4 class="font-display text-2xl text-gray-900 group-hover:underline decoration-emerald-600 underline-offset-4">
          {{$sermon->title}}
        </h4>
      </a>
    @endif

    <ul class="mt-4 space-y-2 prose">
      @if (($sermon->date != null))
      <li class="flex items-center">
        <x-heroicon-s-calendar class="h-5 w-5 mr-2 text-gray-500" />
        {{ $sermon->date->format('j F Y') }}
      </li>
      @endif
      @if ($sermon->service != null)
      <li class="flex items-center">
        <x-heroicon-o-clock class="h-5 w-5 mr-2 text-gray-500" />
        {{ $sermon->service instanceof \App\Enums\SermonService ? $sermon->service->label() : \Illuminate\Support\Str::title($sermon->service) }}
      </li>
      @endif
      @if ($sermon->preacher != null)
      <li class="flex items-center">
        <x-heroicon-o-user class="h-5 w-5 mr-2 text-gray-500" />
        <a href="{{ $sermon->preacher_url }}" wire:navigate class="hover:text-emerald-700 transition-colors">{{ $sermon->preacherProfile->name ?? $sermon->preacher }}</a>
      </li>
      @endif
      @if ($sermon->series != null)
      <li class="flex items-center">
        <x-heroicon-o-tag class="h-5 w-5 mr-2 text-gray-500" />
        <a href="/christ/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}" wire:navigate class="hover:text-emerald-700 transition-colors">{{ $sermon->series }}</a>
      </li>
      @endif
      @if ($sermon->reference != null)
      <li class="flex items-center">
        <x-heroicon-o-book-open class="h-5 w-5 mr-2 text-gray-500" />
        {{ $sermon->reference }}
      </li>
      @endif
    </ul>
  </div>

  @can ('edit-sermons')
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
