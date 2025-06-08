@extends('layouts/page')

@section('dynamic_content')

@php
use Illuminate\Support\Str;
// Define $sermon if not already available, though it should be.
// $sermon is passed to this view.
@endphp

{{-- Existing content of @section('dynamic_content') follows --}}

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

  <audio src="{{ Storage::url($sermon->filename) }}" class="my-6 w-full" controls>
    Your browser does not support the <code>audio</code> element.
  </audio>

  @if (!empty($sermon->points) && is_array($sermon->points))
  <h2 class="mt-6 text-2xl font-semibold">Sermon Outline</h2>
  <section class="sermon-headings prose lg:prose-xl max-w-none mt-4 mb-6">
    <ol>
      @foreach ($sermon->points as $pointItem)
      @if (is_array($pointItem))
      @php
      $mainPointText = (isset($pointItem['point']) && is_scalar($pointItem['point'])) ? (string) $pointItem['point'] : null;
      $subPointsArray = (isset($pointItem['sub_points']) && is_array($pointItem['sub_points'])) ? $pointItem['sub_points'] : [];
      @endphp

      {{-- Only create a list item if there's a main point or sub-points to show --}}
      @if (!empty($mainPointText) || !empty($subPointsArray))
      <li>
        @if (!empty($mainPointText))
        <span class="font-semibold text-lg">{{ $mainPointText }}</span>
        @endif

        @if (!empty($subPointsArray))
        <ul class="ml-4"> {{-- Indent sub-points --}}
          @foreach ($subPointsArray as $subPoint)
          @if (is_scalar($subPoint))
          <li>{{ (string) $subPoint }}</li>
          @endif
          @endforeach
        </ul>
        @endif
      </li>
      @endif
      @elseif (is_scalar($pointItem))
      {{-- Fallback for old data if $pointItem is just a string --}}
      <li class="font-semibold text-lg">{{ (string) $pointItem }}</li>
      @endif
      @endforeach
    </ol>
  </section>
  @endif

  <x-button link="{{ Storage::url($sermon->filename) }}">
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