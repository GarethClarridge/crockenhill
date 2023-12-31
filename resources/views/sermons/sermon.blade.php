@extends('layouts/page')

@section('dynamic_content')

@if (($sermon->points != null))
<h2>Sermon outline</h2>

<section class="sermon-headings">
  {!!trim($sermon->points)!!}
</section>
@endif

<section class="space-y-6">
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
      {{ \Illuminate\Support\Str::title($sermon->service) }}
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

  <audio src="/media/sermons/{{$sermon->filename}}.mp3" class="my-6 w-full" controls>
    Your browser does not support the <code>audio</code> element.
  </audio>

  <x-button link="/media/sermons/{{$sermon->filename}}.mp3">
    <div class="flex items-center justify-center">
      <x-heroicon-s-folder-arrow-down class="h-5 w-5 mr-2" />
      Download this sermon
    </div>
  </x-button>

</section>

@can ('edit-sermons')
<form method="POST" action="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/delete" accept-charset="UTF-8" class="mt-6 grid grid-cols-2">
  <input type="hidden" name="_token" value="{{ csrf_token() }}">
  <a href="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-l-md bg-cbc-pattern bg-cover focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
    <div class="flex items-center justify-center">
      <x-heroicon-s-pencil-square class="h-6 w-6 mr-2" />
      Edit
    </div>
  </a>

  <button type="submit" class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-r-md bg-gradient-to-r from-rose-600 to-rose-700 focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
    <div class="flex items-center justify-center">
      <x-heroicon-s-trash class="h-6 w-6 mr-2" />
      Delete
    </div>
  </button>
</form>
@endcan

<small class="prose">
  If there are any problems with this sermon, please email
  <a href="mailto:sermons@crockenhill.org">sermons@crockenhill.org</a>.
</small>

@stop