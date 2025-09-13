@extends('layouts/page')

@section('dynamic_content')

@php
use Illuminate\Support\Str;
// Define $sermon if not already available, though it should be.
// $sermon is passed to this view.
@endphp

{{-- Existing content of @section('dynamic_content') follows --}}

<section class="space-y-8">
  <div class="bg-white border border-gray-200 rounded-t-lg mb-0 p-6 shadow-sm">
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

      <audio src="{{ route('serveSermonAudio', $sermon->slug) }}" class="w-full" controls>
        Your browser does not support the <code>audio</code> element.
      </audio>

      @if (!empty($sermon->video_file_path))
        <video src="{{ Storage::disk(config('livestream-processing.sermon_disk', 'local'))->url($sermon->video_file_path) }}" class="w-full max-h-96" controls>
          Your browser does not support the <code>video</code> element.
        </video>
        @endif

    </dl>

    {{-- Sermon Summary --}}
    @if (!empty($sermon->summary))
    <div class="mt-8 p-6 bg-blue-50 border border-blue-200 rounded-lg">
      <h2 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
        <x-heroicon-o-document-text class="h-5 w-5 mr-2" />
        Summary
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
          Automated transcript (may contain errors)
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
  {{-- Admin Actions --}}
  @can ('edit-sermons')
  <div class="">

    @if ($sermon->source_type === 'livestream' && $sermon->livestreamProcessing)
    <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
      <h4 class="text-md font-semibold text-gray-900 mb-3 flex items-center">
        <x-heroicon-o-signal class="h-4 w-4 mr-2" />
        Livestream Processing Information
      </h4>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
        <div>
          <span class="font-medium text-gray-700">Original File:</span>
          <span class="text-gray-600">{{ $sermon->livestreamProcessing->original_filename }}</span>
        </div>
        <div>
          <span class="font-medium text-gray-700">Processing Date:</span>
          <span class="text-gray-600">{{ $sermon->livestreamProcessing->created_at->format('Y-m-d H:i:s') }}</span>
        </div>
        <div>
          <span class="font-medium text-gray-700">Status:</span>
          <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full 
            @if($sermon->livestreamProcessing->status === 'completed') bg-green-100 text-green-800
            @elseif($sermon->livestreamProcessing->status === 'failed') bg-red-100 text-red-800
            @elseif($sermon->livestreamProcessing->status === 'processing') bg-yellow-100 text-yellow-800
            @else bg-gray-100 text-gray-800 @endif">
            {{ ucfirst($sermon->livestreamProcessing->status) }}
          </span>
        </div>
        <div>
          <span class="font-medium text-gray-700">Total Segments:</span>
          <span class="text-gray-600">{{ $sermon->livestreamProcessing->segments->count() }}</span>
        </div>
        @if ($sermon->livestreamProcessing->duration_seconds)
        <div>
          <span class="font-medium text-gray-700">Total Duration:</span>
          <span class="text-gray-600">{{ gmdate('H:i:s', $sermon->livestreamProcessing->duration_seconds) }}</span>
        </div>
        @endif
        @if ($sermon->livestreamProcessing->processing_id)
        <div>
          <span class="font-medium text-gray-700">Processing ID:</span>
          <span class="text-gray-600 font-mono text-xs">{{ $sermon->livestreamProcessing->processing_id }}</span>
        </div>
        @endif
      </div>
    </div>
    @endif

    <x-admin-actions 
      :editRoute="'/christ/sermons/' . date('Y', strtotime($sermon->date)) . '/' . date('m', strtotime($sermon->date)) . '/' . $sermon->slug . '/edit'"
      :deleteRoute="'/christ/sermons/' . date('Y', strtotime($sermon->date)) . '/' . date('m', strtotime($sermon->date)) . '/' . $sermon->slug . '/delete'"
      deleteConfirmMessage="Are you sure you want to delete this sermon? This action cannot be undone."
      layout="grid"
      :withIcons="true" />
  </div>
  @endcan

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


@stop