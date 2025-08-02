@props([
'sermon',
])

<div class="max-w-sm rounded-lg shadow bg-white border-1 border-gray-300 p-0 m-2">

  @if (($sermon->title != null))
  <a class="font-display text-4xl my-auto underline" href="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">
    <h4 class="p-6 mx-6 mt-6">
      {{$sermon->title}}
      @if (!empty($sermon->video_file_path))
        <span class="ml-2 inline-flex items-center">
          <x-heroicon-s-video-camera class="h-4 w-4 text-green-600" title="Video Available" />
        </span>
      @endif
      @if ($sermon->source_type === 'livestream')
        <span class="ml-1 inline-flex items-center">
          <x-heroicon-s-signal class="h-4 w-4 text-blue-600" title="From Livestream" />
        </span>
      @endif
    </h4>
  </a>
  @endif
  <ul class="mx-6 px-6 mb-6 pb-6 prose">
    @if (($sermon->date != null))
    <li class="my-2 flex items-center">
      <x-heroicon-s-calendar class="h-5 w-5 mr-2" />
      {{ date('j F Y', strtotime($sermon->date)) }}
    </li>
    @endif
    @if ($sermon->service != null)
    <li class="my-2 flex items-center">
      <x-heroicon-o-clock class="h-5 w-5 mr-2" />
      {{ $sermon->service instanceof \App\Enums\SermonService ? $sermon->service->label() : \Illuminate\Support\Str::title($sermon->service) }}
    </li>
    @endif
    @if ($sermon->preacher != null)
    <li class="my-2 flex items-center">
      <x-heroicon-o-user class="h-5 w-5 mr-2" />
      <a href="/christ/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
    </li>
    @endif
    @if ($sermon->series != null)
    <li class="my-2 flex items-center">
      <x-heroicon-o-tag class="h-5 w-5 mr-2" />
      <a href="/christ/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}">{{ $sermon->series }}</a>
    </li>
    @endif
    @if ($sermon->reference != null)
    <li class="my-2 flex items-center">
      <x-heroicon-o-book-open class="h-5 w-5 mr-2" />
      {{ $sermon->reference }}
    </li>
    @endif
  </ul>

  @can ('edit-sermons')
    <x-admin-actions 
      :editRoute="'/christ/sermons/' . date('Y', strtotime($sermon->date)) . '/' . date('m', strtotime($sermon->date)) . '/' . $sermon->slug . '/edit'"
      :deleteRoute="'/christ/sermons/' . date('Y', strtotime($sermon->date)) . '/' . date('m', strtotime($sermon->date)) . '/' . $sermon->slug . '/delete'"
      deleteConfirmMessage="Are you sure you want to delete this sermon?"
      layout="grid"
      :withIcons="true" />
  @endcan
</div>