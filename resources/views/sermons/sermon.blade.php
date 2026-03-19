@extends('layouts/page')

@php
use Illuminate\Support\Str;
$fullTitle = $sermon->title . ' | ' . ($sermon->preacherProfile?->name ?? $sermon->preacher);
@endphp

@section('title'){{ $fullTitle }}@stop

@section('canonical')
<link rel="canonical" href="{{ $sermon->canonical_url }}">
@endsection

@section('meta_tags')
<x-meta-tags
  :title="$fullTitle"
  :description="$description ?? $sermon->meta_description"
  type="article"
  :image="$sermon->thumbnail_url && $sermon->hasThumbnail() ? $sermon->thumbnail_url : null"
  :image-width="$sermon->thumbnail_url && $sermon->hasThumbnail() ? 1280 : 800"
  :image-height="$sermon->thumbnail_url && $sermon->hasThumbnail() ? 720 : 600"
  :image-alt="'Sermon: ' . $sermon->title"
  :audio="$sermon->audio_url"
  :video="$sermon->video_url"
  :canonical="$sermon->canonical_url" />

{{-- JSON-LD Structured Data --}}
@php
$transcript = $sermon->transcript;
$duration = $sermon->duration ? \Carbon\CarbonInterval::seconds($sermon->duration)->cascade()->spec() : null;

$schema = [
'@context' => 'https://schema.org',
'@type' => 'Article',
'headline' => $sermon->title,
'description' => $sermon->meta_description,
'image' => $sermon->thumbnail_url ?: asset('images/Primary.png'),
'datePublished' => $sermon->date->toIso8601String(),
'author' => [
'@type' => 'Person',
'name' => $sermon->preacherProfile?->name ?? $sermon->preacher,
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

if ($duration) {
$schema['video']['duration'] = $duration;
}

if ($transcript) {
$schema['video']['transcript'] = $transcript;
}
}

if ($sermon->audio_url) {
$schema['audio'] = [
'@type' => 'AudioObject',
'name' => $sermon->title,
'contentUrl' => $sermon->audio_url,
'description' => $sermon->meta_description,
'encodingFormat' => 'audio/mpeg',
'uploadDate' => $sermon->date->toIso8601String(),
];

if ($duration) {
$schema['audio']['duration'] = $duration;
}

if ($transcript) {
$schema['audio']['transcript'] = $transcript;
}
}

@endphp
<script type="application/ld+json">
    {!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@endsection

@section('dynamic_content')

<article class="space-y-6">

  {{-- ══ Row 1: Hero thumbnail / Media (full width) ══════════ --}}

  {{-- ── Hero thumbnail (video-less sermons only) ─────────── --}}
  @if(!$sermon->video_file_path && $sermon->hasThumbnail())
  <div class="overflow-hidden rounded-xl shadow-sm border border-gray-100">
    <img
      src="{{ route('serveSermonThumbnail', $sermon->slug) }}"
      alt="Sermon: {{ $sermon->title }}"
      class="w-full max-h-96 object-cover">
  </div>
  @endif

  {{-- ── Media ─────────────────────────────────────────────── --}}
  @if ($sermon->audio_file_path || !empty($sermon->video_file_path))
  <div class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-2">
      <x-heroicon-o-play-circle class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
      <h2 class="font-display text-xl text-gray-900">
        @if ($sermon->audio_file_path && !empty($sermon->video_file_path))
        Watch or listen
        @elseif (!empty($sermon->video_file_path))
        Watch
        @else
        Listen
        @endif
      </h2>
    </div>
    <div class="p-6 space-y-6">
      @if ($sermon->audio_file_path)
      <audio src="{{ $sermon->audio_url }}" class="w-full rounded-lg" controls>
        Your browser does not support the <code>audio</code> element.
      </audio>
      @endif

      @if (!empty($sermon->video_file_path))
      <video src="{{ Storage::disk(config('media-processing.storage.sermon_disk', 'public'))->url($sermon->video_file_path) }}"
        class="w-full rounded-lg"
        controls
        @if($sermon->thumbnail_url && $sermon->hasThumbnail()) poster="{{ $sermon->thumbnail_url }}" @endif>
        Your browser does not support the <code>video</code> element.
      </video>
      @endif
    </div>
  </div>
  @endif

  {{-- ══ Row 2: Content + Details (two equal-ish columns) ═══ --}}
  <div class="lg:grid lg:grid-cols-5 lg:gap-8 lg:items-start">

    {{-- ── Left: summary / outline / transcript ─────────────── --}}
    <div class="lg:col-span-3 space-y-6">

      {{-- ── Summary ─────────────────────────────────────────── --}}
      @if ($sermon->show_summary && !empty($sermon->summary))
      <div class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-2">
          <x-heroicon-o-document-text class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
          <h2 class="font-display text-xl text-gray-900">Summary</h2>
        </div>
        <div class="p-6 prose prose-gray max-w-none text-gray-700">
          {{ $sermon->summary }}
        </div>
      </div>
      @endif

      {{-- ── Sermon Outline ───────────────────────────────────── --}}
      @if ($sermon->show_points && !empty($sermon->points) && is_array($sermon->points))
      <div class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-2">
          <x-heroicon-o-list-bullet class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
          <h2 class="font-display text-xl text-gray-900">Sermon Outline</h2>
        </div>
        <div class="p-6 prose prose-gray max-w-none">
          <ol class="space-y-3">
            @foreach ($sermon->points as $pointItem)
            @if (is_array($pointItem))
            @php
            $mainPointText = (isset($pointItem['point']) && is_scalar($pointItem['point'])) ? (string) $pointItem['point'] : null;
            $subPointsArray = (isset($pointItem['sub_points']) && is_array($pointItem['sub_points'])) ? $pointItem['sub_points'] : [];
            @endphp

            @if (!empty($mainPointText) || !empty($subPointsArray))
            <li>
              @if (!empty($mainPointText))
              <div class="text-lg text-gray-900 mb-2">{{ $mainPointText }}</div>
              @endif

              @if (!empty($subPointsArray))
              <ul class="ml-4 space-y-1 text-gray-700">
                @foreach ($subPointsArray as $subPoint)
                @if (is_scalar($subPoint))
                <li class="flex items-start">
                  <span class="inline-block w-2 h-2 rounded-full mt-2 mr-3 flex-shrink-0 bg-cbc-teal/40"></span>
                  <span>{{ (string) $subPoint }}</span>
                </li>
                @endif
                @endforeach
              </ul>
              @endif
            </li>
            @endif
            @elseif (is_scalar($pointItem))
            <li>
              <div class="text-lg text-gray-900">{{ (string) $pointItem }}</div>
            </li>
            @endif
            @endforeach
          </ol>
        </div>
      </div>
      @endif

      {{-- ── Transcript ───────────────────────────────────────── --}}
      @if (is_string($transcript) && trim($transcript) !== '')
      <div
        x-data="{
          expanded: false,
          copied: false,
          async copyTranscript() {
            if (!('clipboard' in navigator)) {
              return;
            }

            await navigator.clipboard.writeText(@js($transcript));
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
          }
        }"
        class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-2">
            <x-heroicon-o-document-text class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
            <h2 class="font-display text-xl text-gray-900">Automated transcript</h2>
            <span class="text-xs text-gray-400 font-sans">(may contain errors)</span>
          </div>
          <div class="flex items-center gap-2">
            <button
              type="button"
              x-show="'clipboard' in navigator"
              @click="copyTranscript()"
              class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-cbc-teal-dark hover:text-cbc-teal bg-white border border-gray-200 hover:border-cbc-teal-light/30 rounded-md shadow-sm transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-1"
              aria-label="Copy transcript"
              title="Copy transcript to clipboard"
              x-cloak>
              <x-heroicon-o-clipboard-document x-show="!copied" class="w-4 h-4" />
              <x-heroicon-o-check x-show="copied" class="w-4 h-4 text-cbc-teal" x-cloak />
              <span x-text="copied ? 'Copied!' : 'Copy Transcript'">Copy Transcript</span>
            </button>

            <button
              class="inline-flex items-center gap-1 px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900 border border-gray-200 hover:border-gray-300 rounded-md bg-white transition-colors focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2"
              @click="expanded = !expanded"
              :aria-expanded="expanded"
              aria-controls="transcript-content">
              <span x-text="expanded ? 'Hide' : 'Show'">Show</span>
              <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform duration-200" x-bind:class="expanded ? 'rotate-180' : ''" />
            </button>
          </div>
        </div>

        <div
          id="transcript-content"
          x-show="expanded"
          x-cloak
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 -translate-y-1"
          x-transition:enter-end="opacity-100 translate-y-0"
          class="p-6 max-h-96 overflow-y-auto">
          <div class="prose prose-gray max-w-none text-gray-700">
            {!! Str::markdown($transcript, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            ]) !!}
          </div>
        </div>
      </div>
      @endif

    </div>{{-- end left column --}}

    {{-- ── Right: metadata + passage + admin ───────────────── --}}
    <div class="mt-6 lg:mt-0 lg:col-span-2 space-y-6 lg:sticky lg:top-6">

      {{-- ── Metadata card ──────────────────────────────────── --}}
      <div class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
        <div class="h-1 w-full bg-[linear-gradient(90deg,theme(colors.cbc-teal.light)_0%,theme(colors.cbc-teal.DEFAULT)_55%,theme(colors.cbc-teal.dark)_100%)]"></div>
        <dl class="p-6 space-y-4">

          @if ($sermon->date != null)
          <div class="flex items-center gap-3">
            <x-heroicon-s-calendar class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
            <div>
              <dt class="sr-only">Date</dt>
              <dd class="text-gray-900 font-medium">{{ $sermon->date->format('j F Y') }}</dd>
            </div>
          </div>
          @endif

          @if ($sermon->service != null)
          <div class="flex items-center gap-3">
            <x-heroicon-o-clock class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
            <div>
              <dt class="sr-only">Service</dt>
              <dd class="text-gray-900 font-medium">{{ $sermon->service instanceof \App\Enums\SermonService ? $sermon->service->label() : \Illuminate\Support\Str::title($sermon->service) }}</dd>
            </div>
          </div>
          @endif

          @if ($sermon->preacher != null)
          <div class="flex items-center gap-3">
            <x-heroicon-o-user class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
            <div>
              <dt class="sr-only">Preacher</dt>
              <dd class="text-gray-900 font-medium">
                <a href="{{ $sermon->preacher_url }}" wire:navigate class="text-cbc-teal-dark hover:text-cbc-teal transition-colors underline underline-offset-2 decoration-cbc-teal/40">{{ $sermon->preacherProfile->name ?? $sermon->preacher }}</a>
              </dd>
            </div>
          </div>
          @endif

          @if ($sermon->series != null)
          <div class="flex items-center gap-3">
            <x-heroicon-o-tag class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
            <div>
              <dt class="sr-only">Series</dt>
              <dd class="text-gray-900 font-medium">
                <a href="/christ/sermons/series/{{ \Illuminate\Support\Str::slug($sermon->series) }}" wire:navigate class="text-cbc-teal-dark hover:text-cbc-teal transition-colors underline underline-offset-2 decoration-cbc-teal/40">{{ $sermon->series }}</a>
              </dd>
            </div>
          </div>
          @endif

        </dl>
      </div>

      {{-- ── Bible passage ──────────────────────────────────── --}}
      @if ($sermon->reference != null)
      <div
        x-data="{ expanded: false }"
        class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-2">
            <x-heroicon-o-book-open class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
            <div>
              @if (! empty($readingReference))
              <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cbc-teal-dark/75 mb-0.5">Passage</p>
              @endif
              <span class="font-display text-xl text-gray-900">{{ $sermon->reference }}</span>
            </div>
          </div>
          @if ($sermon->scripturePassage || ! empty($readingReference))
          <button
            class="inline-flex items-center gap-1 px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900 border border-gray-200 hover:border-gray-300 rounded-md bg-white transition-colors focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2"
            @click="expanded = !expanded"
            :aria-expanded="expanded"
            aria-controls="passage-content">
            <span x-text="expanded ? 'Hide' : 'Read passage'">Show passage</span>
            <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform duration-200" x-bind:class="expanded ? 'rotate-180' : ''" />
          </button>
          @endif
        </div>

        <div
          id="passage-content"
          x-show="expanded"
          x-cloak
          x-transition:enter="transition ease-out duration-200"
          x-transition:enter-start="opacity-0 -translate-y-1"
          x-transition:enter-end="opacity-100 translate-y-0">
          @if ($sermon->scripturePassage)
          <div class="p-6">
            <div
              class="scripture-passage prose prose-sm prose-gray max-w-none text-gray-700 leading-relaxed"
              data-fums-token="{{ $sermon->scripturePassage->fums_token }}">
              {!! $sermon->scripturePassage->html_content !!}
            </div>
            <p class="mt-2 text-xs text-gray-400">{{ $sermon->scripturePassage->copyright }}</p>
          </div>
          @endif

          @if (! empty($readingReference))
          <div class="border-t border-gray-100 px-6 py-4 bg-gray-50/50">
            <div class="flex items-center gap-3">
              <x-heroicon-o-book-open class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
              <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cbc-teal-dark/75 mb-0.5">Reading</p>
                <a href="{{ $readingUrl }}" class="text-cbc-teal-dark underline decoration-cbc-teal/50 underline-offset-2 hover:text-cbc-teal-deeper font-medium" target="_blank" rel="noopener noreferrer">
                  {{ $readingReference }}
                </a>
              </div>
            </div>
          </div>
          @endif
        </div>
      </div>
      @endif

      {{-- ── Admin Actions ──────────────────────────────────── --}}
      @can ('manage-sermons')
      <div>

        @if ($sermon->source_type === 'livestream' && $sermon->livestreamProcessing)
        <div class="mb-4 p-4 bg-gray-50 border border-gray-200 rounded-xl">
          <h4 class="font-display text-lg text-gray-900 mb-3 flex items-center gap-2">
            <x-heroicon-o-signal class="h-4 w-4 text-cbc-teal" />
            Livestream Processing
          </h4>
          <div class="grid grid-cols-1 gap-4 text-sm">
            <div>
              <span class="font-medium text-gray-700">Original File:</span>
              <span class="text-gray-600"> {{ $sermon->livestreamProcessing->original_filename }}</span>
            </div>
            <div>
              <span class="font-medium text-gray-700">Processing Date:</span>
              <span class="text-gray-600"> {{ $sermon->livestreamProcessing->created_at->format('Y-m-d H:i:s') }}</span>
            </div>
            <div>
              <span class="font-medium text-gray-700">Status:</span>
              <span class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-full ml-1
                @if($sermon->livestreamProcessing->status->value === 'completed') bg-green-100 text-green-800
                @elseif($sermon->livestreamProcessing->status->value === 'failed') bg-red-100 text-red-800
                @elseif($sermon->livestreamProcessing->status->value === 'processing') bg-yellow-100 text-yellow-800
                @else bg-gray-100 text-gray-800 @endif">
                {{ $sermon->livestreamProcessing->status->label() }}
              </span>
            </div>
            <div>
              <span class="font-medium text-gray-700">Total Segments:</span>
              <span class="text-gray-600"> {{ $sermon->livestreamProcessing->segments->count() }}</span>
            </div>
            @if ($sermon->livestreamProcessing->duration_seconds)
            <div>
              <span class="font-medium text-gray-700">Total Duration:</span>
              <span class="text-gray-600"> {{ gmdate('H:i:s', $sermon->livestreamProcessing->duration_seconds) }}</span>
            </div>
            @endif
            @if ($sermon->livestreamProcessing->processing_id)
            <div>
              <span class="font-medium text-gray-700">Processing ID:</span>
              <span class="text-gray-600 font-mono text-xs"> {{ $sermon->livestreamProcessing->processing_id }}</span>
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

    </div>{{-- end right column --}}

  </div>
</article>

@stop