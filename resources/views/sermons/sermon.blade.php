@extends('layouts/page')

@php
use Illuminate\Support\Str;
@endphp

@section('meta_tags')
<x-meta-tags
    :title="$sermon->title"
    :description="$sermon->meta_description"
    type="article"
    :image="$sermon->thumbnail_url && $sermon->hasThumbnail() ? $sermon->thumbnail_url : null"
    :image-width="$sermon->thumbnail_url && $sermon->hasThumbnail() ? 1280 : 800"
    :image-height="$sermon->thumbnail_url && $sermon->hasThumbnail() ? 720 : 600"
    :image-alt="'Sermon: ' . $sermon->title"
    :audio="$sermon->audio_url"
    :video="$sermon->video_url"
    :canonical="route('showSermon', $sermon->slug)"
/>

{{-- JSON-LD Structured Data --}}
@php
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $sermon->title,
        'image' => $sermon->thumbnail_url ?: asset('images/Primary.png'),
        'datePublished' => $sermon->date->toIso8601String(),
        'author' => [
            '@type' => 'Person',
            'name' => $sermon->preacherProfile->name ?? $sermon->preacher,
        ],
    ];

    if ($sermon->video_url) {
        $schema['video'] = [
            '@type' => 'VideoObject',
            'name' => $sermon->title,
            'description' => $sermon->meta_description,
            'thumbnailUrl' => $sermon->thumbnail_url ?: asset('images/Primary.png'),
            'uploadDate' => $sermon->date->toIso8601String(),
            'contentUrl' => $sermon->video_url,
        ];
    }

    if ($sermon->audio_url) {
        $schema['audio'] = [
            '@type' => 'AudioObject',
            'contentUrl' => $sermon->audio_url,
            'description' => $sermon->meta_description,
            'encodingFormat' => 'audio/mpeg',
        ];
    }

    // Build breadcrumb schema
    $breadcrumbItems = [
        ['name' => 'Home', 'item' => url('/')],
        ['name' => 'Christ', 'item' => url('christ')],
        ['name' => 'Sermons', 'item' => url('christ/sermons')],
        ['name' => $sermon->title, 'item' => url()->current()],
    ];

    $breadcrumbList = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => array_map(function ($item, $index) {
            return [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['item'],
            ];
        }, $breadcrumbItems, array_keys($breadcrumbItems)),
    ];
@endphp
<script type="application/ld+json">
    {!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
<script type="application/ld+json">
    {!! json_encode($breadcrumbList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@endsection

@section('dynamic_content')

@php
// $sermon is passed to this view from the controller
@endphp

{{-- Existing content of @section('dynamic_content') follows --}}

<section class="space-y-8">
  <div class="bg-white border border-gray-200 rounded-t-lg mb-0 p-6 shadow-sm">
    @if(!$sermon->video_file_path && $sermon->hasThumbnail())
      <div class="mb-8 overflow-hidden rounded-lg shadow-sm border border-gray-100">
        <img src="{{ route('serveSermonThumbnail', $sermon->slug) }}" alt="Sermon: {{ $sermon->title }}" class="w-full max-h-96 object-cover">
      </div>
    @endif

    <dl class="space-y-6">
      @if ($sermon->date != null)
      <div class="flex items-center">
        <x-heroicon-s-calendar class="h-5 w-5 text-gray-500 mr-3 flex-shrink-0" aria-hidden="true" />
        <div>
          <dt class="sr-only">Date</dt>
          <dd class="text-gray-900 font-medium">{{ $sermon->date->format('j F Y') }}</dd>
        </div>
      </div>
      @endif

      @if ($sermon->service != null)
      <div class="flex items-center">
        <x-heroicon-o-clock class="h-5 w-5 text-gray-500 mr-3 flex-shrink-0" aria-hidden="true" />
        <div>
          <dt class="sr-only">Service</dt>
          <dd class="text-gray-900 font-medium">{{ $sermon->service instanceof \App\Enums\SermonService ? $sermon->service->label() : \Illuminate\Support\Str::title($sermon->service) }}</dd>
        </div>
      </div>
      @endif

      @if ($sermon->preacher != null)
      <div class="flex items-center">
        <x-heroicon-o-user class="h-5 w-5 text-gray-500 mr-3 flex-shrink-0" aria-hidden="true" />
        <div>
          <dt class="sr-only">Preacher</dt>
          <dd class="text-gray-900 font-medium">
            <a href="{{ $sermon->preacher_url }}" wire:navigate class="text-blue-600 hover:text-blue-800 transition-colors">{{ $sermon->preacherProfile->name ?? $sermon->preacher }}</a>
          </dd>
        </div>
      </div>
      @endif

      @if ($sermon->series != null)
      <div class="flex items-center">
        <x-heroicon-o-tag class="h-5 w-5 text-gray-500 mr-3 flex-shrink-0" aria-hidden="true" />
        <div>
          <dt class="sr-only">Series</dt>
          <dd class="text-gray-900 font-medium">
            <a href="/christ/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}" wire:navigate class="text-blue-600 hover:text-blue-800 transition-colors">{{ $sermon->series }}</a>
          </dd>
        </div>
      </div>
      @endif

      @if ($sermon->reference != null)
      <div class="flex items-center md:col-span-2">
        <x-heroicon-o-book-open class="h-5 w-5 text-gray-500 mr-3 flex-shrink-0" aria-hidden="true" />
        <div>
          <dt class="sr-only">Bible Reference</dt>
          <dd class="text-gray-900 font-medium">{{ $sermon->reference }}</dd>
        </div>
      </div>
      @endif

    </dl>

    {{-- Sermon Summary --}}
    @if ($sermon->show_summary && !empty($sermon->summary))
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
    @if ($sermon->show_points && !empty($sermon->points) && is_array($sermon->points))
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

    @if ($sermon->audio_file_path)
      <audio src="{{ $sermon->audio_url }}" class="w-full rounded-lg my-12" controls>
        Your browser does not support the <code>audio</code> element.
      </audio>
    @endif

      @if (!empty($sermon->video_file_path))
        <video src="{{ Storage::disk(config('media-processing.storage.sermon_disk', 'public'))->url($sermon->video_file_path) }}"
               class="w-full max-h-96 rounded-lg my-12"
               controls
               @if($sermon->thumbnail_url && $sermon->hasThumbnail()) poster="{{ $sermon->thumbnail_url }}" @endif>
          Your browser does not support the <code>video</code> element.
        </video>
      @endif

    {{-- Transcript Section --}}
    @php
      $transcriptContent = $sermon->transcript;
    @endphp
    @if (is_string($transcriptContent) && trim($transcriptContent) !== '')
    <div x-data="{
        expanded: false,
        copied: false,
        async copyTranscript() {
          if (!('clipboard' in navigator)) {
            return;
          }

          await navigator.clipboard.writeText(@js($transcriptContent));
          this.copied = true;
          setTimeout(() => this.copied = false, 2000);
        }
      }" class="mt-6 py-6 border-b border-gray-200">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-xl font-semibold text-gray-900 flex items-center">
          <x-heroicon-o-document-text class="h-5 w-5 mr-2" />
          Automated transcript (may contain errors)
        </h2>
        <div class="flex items-center gap-2">
          <button
            type="button"
            x-show="'clipboard' in navigator"
            @click="copyTranscript()"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-cbc-teal-dark hover:text-cbc-teal bg-white border border-gray-200 hover:border-cbc-teal-light/30 rounded-md shadow-sm transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-1"
            aria-label="Copy transcript"
            title="Copy transcript to clipboard"
            x-cloak
          >
            <x-heroicon-o-clipboard-document x-show="!copied" class="w-4 h-4" />
            <x-heroicon-o-check x-show="copied" class="w-4 h-4 text-cbc-teal" x-cloak />
            <span x-text="copied ? 'Copied!' : 'Copy Transcript'">Copy Transcript</span>
          </button>

          <button
            class="text-sm text-gray-600 hover:text-gray-900 transition-colors focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2 rounded px-2 py-1 flex items-center"
            @click="expanded = !expanded"
            :aria-expanded="expanded"
            aria-controls="transcript-content">
            <span x-text="expanded ? 'Hide Transcript' : 'Show Full Transcript'">Show Full Transcript</span>
            <x-heroicon-o-chevron-down class="h-4 w-4 inline ml-1 transition-transform duration-200" x-bind:class="expanded ? 'rotate-180' : ''" />
          </button>
        </div>
      </div>

      <div id="transcript-content"
           x-show="expanded"
           x-cloak
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0 transform -translate-y-2"
           x-transition:enter-end="opacity-100 transform translate-y-0"
           class="p-6 max-h-96 overflow-y-auto">
        <div class="prose prose-gray max-w-none text-gray-700">
          {!! Str::markdown($transcriptContent, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
          ]) !!}
        </div>
      </div>
    </div>
    @endif
  </div>
  {{-- Admin Actions --}}
  @can ('manage-sermons')
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
            @if($sermon->livestreamProcessing->status->value === 'completed') bg-green-100 text-green-800
            @elseif($sermon->livestreamProcessing->status->value === 'failed') bg-red-100 text-red-800
            @elseif($sermon->livestreamProcessing->status->value === 'processing') bg-yellow-100 text-yellow-800
            @else bg-gray-100 text-gray-800 @endif">
            {{ $sermon->livestreamProcessing->status->label() }}
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


</section>


@stop
