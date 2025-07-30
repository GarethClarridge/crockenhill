@extends('layouts/page')

@section('dynamic_content')

@php
use Illuminate\Support\Str;
// Define $sermon if not already available, though it should be.
// $sermon is passed to this view.
@endphp

{{-- Existing content of @section('dynamic_content') follows --}}

<section class="space-y-8">
  <div class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
    <dl class="space-y-6">
      @if ($sermon->date != null)
      <div class="flex items-center">
        <x-heroicon-s-calendar class="h-5 w-5 text-gray-500 mr-3 flex-shrink-0" />
        <div>
          <dt class="sr-only">Date</dt>
          <dd class="text-gray-900 font-medium">{{ date('j F Y', strtotime($sermon->date)) }}</dd>
        </div>
      </div>
      @endif

      @if ($sermon->service != null)
      <div class="flex items-center">
        <x-heroicon-o-clock class="h-5 w-5 text-gray-500 mr-3 flex-shrink-0" />
        <div>
          <dt class="sr-only">Service</dt>
          <dd class="text-gray-900 font-medium">{{ $sermon->service instanceof \App\Enums\SermonService ? $sermon->service->label() : \Illuminate\Support\Str::title($sermon->service) }}</dd>
        </div>
      </div>
      @endif

      @if ($sermon->preacher != null)
      <div class="flex items-center">
        <x-heroicon-o-user class="h-5 w-5 text-gray-500 mr-3 flex-shrink-0" />
        <div>
          <dt class="sr-only">Preacher</dt>
          <dd class="text-gray-900 font-medium">
            <a href="/christ/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}" class="text-blue-600 hover:text-blue-800 transition-colors">{{ $sermon->preacher }}</a>
          </dd>
        </div>
      </div>
      @endif

      @if ($sermon->series != null)
      <div class="flex items-center">
        <x-heroicon-o-tag class="h-5 w-5 text-gray-500 mr-3 flex-shrink-0" />
        <div>
          <dt class="sr-only">Series</dt>
          <dd class="text-gray-900 font-medium">
            <a href="/christ/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}" class="text-blue-600 hover:text-blue-800 transition-colors">{{ $sermon->series }}</a>
          </dd>
        </div>
      </div>
      @endif

      @if ($sermon->reference != null)
      <div class="flex items-center md:col-span-2">
        <x-heroicon-o-book-open class="h-5 w-5 text-gray-500 mr-3 flex-shrink-0" />
        <div>
          <dt class="sr-only">Bible Reference</dt>
          <dd class="text-gray-900 font-medium">{{ $sermon->reference }}</dd>
        </div>
      </div>
      @endif

      <audio src="{{ Storage::url($sermon->filename) }}" class="w-full" controls>
        Your browser does not support the <code>audio</code> element.
      </audio>

    </dl>

    {{-- Sermon Summary --}}
    @if (!empty($sermon->summary))
    <div class="mt-8 p-6 bg-blue-50 border border-blue-200 rounded-lg">
      <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
        <x-heroicon-o-document-text class="h-5 w-5 mr-2" />
        Summary
        <span class="ml-2 inline-flex items-center px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full">
          <x-heroicon-s-sparkles class="h-3 w-3 mr-1" />
          AI Generated
        </span>
      </h2>
      <div class="prose prose-gray max-w-none text-gray-700">
        {{ $sermon->summary }}
      </div>
    </div>
    @endif

    {{-- Sermon Outline --}}
    @if (!empty($sermon->points) && is_array($sermon->points))
    <h2 class="text-xl font-semibold text-gray-900 mt-12 mb-4 flex items-center">
      <x-heroicon-o-list-bullet class="h-5 w-5 mr-2" />
      Sermon Outline
      <span class="ml-2 inline-flex items-center px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full">
        <x-heroicon-s-sparkles class="h-3 w-3 mr-1" />
        AI Generated
      </span>
    </h2>
    <div class="prose prose-gray max-w-none">
      <ol class="space-y-3">
        @foreach ($sermon->points as $pointItem)
        @if (is_array($pointItem))
        @php
        $mainPointText = (isset($pointItem['point']) && is_scalar($pointItem['point'])) ? (string) $pointItem['point'] : null;
        $subPointsArray = (isset($pointItem['sub_points']) && is_array($pointItem['sub_points'])) ? $pointItem['sub_points'] : [];
        @endphp

        {{-- Only create a list item if there's a main point or sub-points to show --}}
        @if (!empty($mainPointText) || !empty($subPointsArray))
        <li class="">
          @if (!empty($mainPointText))
          <div class=" text-lg text-gray-900 mb-2">{{ $mainPointText }}</div>
          @endif

          @if (!empty($subPointsArray))
          <ul class="ml-4 space-y-1 text-gray-700">
            @foreach ($subPointsArray as $subPoint)
            @if (is_scalar($subPoint))
            <li class="flex items-start">
              <span class="inline-block w-2 h-2 bg-gray-400 rounded-full mt-2 mr-3 flex-shrink-0"></span>
              <span>{{ (string) $subPoint }}</span>
            </li>
            @endif
            @endforeach
          </ul>
          @endif
        </li>
        @endif
        @elseif (is_scalar($pointItem))
        {{-- Fallback for old data if $pointItem is just a string --}}
        <li class="">
          <div class=" text-lg text-gray-900">{{ (string) $pointItem }}</div>
        </li>
        @endif
        @endforeach
      </ol>
    </div>
    @endif

    {{-- Transcript Section --}}
    @if ($sermon->hasTranscript())
    <div class="mt-6 py-6 border-b border-gray-200">
      <div class="flex items-center justify-between">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center">
          <x-heroicon-o-document-text class="h-5 w-5 mr-2" />
          Transcript
          <span class="ml-2 inline-flex items-center px-2 py-1 text-xs font-medium bg-purple-100 text-purple-800 rounded-full">
            <x-heroicon-s-sparkles class="h-3 w-3 mr-1" />
            AI Generated
          </span>
        </h2>
        <button
          class="text-sm text-gray-600 hover:text-gray-900 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 rounded px-2 py-1"
          onclick="toggleTranscript()"
          id="transcript-toggle-btn">
          <span id="transcript-toggle-text">Show Full Transcript</span>
          <x-heroicon-o-chevron-down class="h-4 w-4 inline ml-1" id="transcript-chevron" />
        </button>
      </div>
    </div>

    <div id="transcript-content" class="p-6 max-h-96 overflow-y-auto hidden">
      <div class="prose prose-gray max-w-none">
        {!! Str::markdown($sermon->transcript) !!}
      </div>
    </div>
  </div>

  <script>
    function toggleTranscript() {
      const content = document.getElementById('transcript-content');
      const toggleText = document.getElementById('transcript-toggle-text');
      const chevron = document.getElementById('transcript-chevron');

      if (content.classList.contains('hidden')) {
        content.classList.remove('hidden');
        toggleText.textContent = 'Hide Transcript';
        chevron.style.transform = 'rotate(180deg)';
      } else {
        content.classList.add('hidden');
        toggleText.textContent = 'Show Full Transcript';
        chevron.style.transform = 'rotate(0deg)';
      }
    }
  </script>
  @endif

</section>

{{-- Admin Actions --}}
@can ('edit-sermons')
<div class="mt-8 bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
  <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
    <x-heroicon-o-cog-6-tooth class="h-5 w-5 mr-2" />
    Admin Actions
  </h3>

  <form method="POST" action="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/delete" accept-charset="UTF-8" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">

    <a href="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit"
      class="inline-flex items-center justify-center px-4 py-3 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all no-underline">
      <x-heroicon-s-pencil-square class="h-5 w-5 mr-2" />
      Edit Sermon
    </a>

    <button type="submit"
      onclick="return confirm('Are you sure you want to delete this sermon? This action cannot be undone.')"
      class="inline-flex items-center justify-center px-4 py-3 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-all">
      <x-heroicon-s-trash class="h-5 w-5 mr-2" />
      Delete Sermon
    </button>
  </form>
</div>
@endcan

@stop