@extends('layouts.main')

@php
    $displayReference = $sermonView['display_reference'];
    $hasPublicAudio = filled($sermonView['audio_url']);
    $hasPublicVideo = filled($sermonView['video_url']);
@endphp

@section('content')
<x-page.shell
    :heading="$heading ?? $sermon->title"
    :description="$description ?? null"
    :metaDescription="$metaDescription ?? null"
    :headingpicture="$headingpicture ?? null"
    :headingpicture-mobile="$headingpictureMobile ?? null"
    :headingpicture-tablet="$headingpictureTablet ?? null"
    :area="$area ?? null"
    :slug="$slug ?? null"
    :links="$links ?? []"
    :canonical="$sermonView['canonical_url']"
    :title="$fullTitle"
    :meta-tags="false"
>
    @push('meta_tags')
        <x-meta-tags
            :title="$fullTitle"
            :description="$description ?? $metaDescription"
            type="article"
            :image="$sermonView['thumbnail_url']"
            :image-width="$sermonView['thumbnail_url'] ? 1280 : 800"
            :image-height="$sermonView['thumbnail_url'] ? 720 : 600"
            :image-alt="app(\App\Presenters\SermonViewPresenter::class)->imageAlt($sermon)"
            :audio="$sermonView['audio_url']"
            :video="$sermonView['video_url']"
            :canonical="$sermonView['canonical_url']"
            :author="$sermonView['preacher_url']"
            :published-time="$sermon->date->toIso8601String()"
            :modified-time="$sermon->updated_at?->year > 0 ? $sermon->updated_at->toIso8601String() : null"
            section="Sermons"
            :tags="$sermon->series"
            label1="Preacher"
            :data1="$sermonView['preacher_name']"
            :label2="$sermon->series ? 'Series' : null"
            :data2="$sermon->series" />

        <x-schema.sermon :$sermon :$sermonView :$metaDescription />
        <x-schema.webpage
            :heading="$fullTitle"
            :description="$description ?? $metaDescription"
            :image="$sermonView['thumbnail_url']"
            :main-entity="$sermonView['canonical_url'] . '#sermon'"
            :datePublished="$sermon->date"
            :dateModified="$sermon->updated_at"
        />
    @endpush

    <article class="space-y-6">

        <x-analytics-context
            :sermon="$sermon"
            :preacher-name="$sermonView['preacher_name']"
            :service-label="$sermonView['service_label'] ?? null" />

        {{-- ══ Row 1: Hero thumbnail / Media (full width) ══════════ --}}

        @if(! $hasPublicVideo && $sermonView['thumbnail_url'])
        <div class="overflow-hidden rounded-xl shadow-sm border border-gray-100">
            <img
                src="{{ $sermonView['thumbnail_url'] }}"
                alt=""
                role="presentation"
                class="w-full max-h-96 object-cover">
        </div>
        @endif

        @if ($hasPublicAudio || $hasPublicVideo)
        <div class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-2">
                <x-heroicon-o-play-circle class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
                <h2 class="font-display text-xl text-gray-900">
                    @if ($hasPublicAudio && $hasPublicVideo)
                    Watch or listen
                    @elseif ($hasPublicVideo)
                    Watch
                    @else
                    Listen
                    @endif
                </h2>
            </div>
            <div class="p-6 space-y-6">
                @if ($hasPublicAudio)
                <audio
                    src="{{ $sermonView['audio_url'] }}"
                    class="w-full rounded-lg"
                    controls
                    data-analytics="sermon-media"
                    data-ga-sermon-slug="{{ $sermon->slug }}">
                    Your browser does not support the <code>audio</code> element.
                </audio>
                <div>
                    <a
                        href="{{ $sermonView['audio_url'] }}"
                        download
                        data-analytics="sermon-download"
                        data-ga-sermon-slug="{{ $sermon->slug }}"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-cbc-teal-dark underline decoration-cbc-teal/40 underline-offset-2 transition-colors hover:text-cbc-teal focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded">
                        <x-heroicon-o-arrow-down-tray class="h-4 w-4" aria-hidden="true" />
                        Download audio
                    </a>
                </div>
                @endif

                @if ($hasPublicVideo)
                <video src="{{ $sermonView['video_url'] }}"
                    class="w-full rounded-lg"
                    controls
                    data-analytics="sermon-media"
                    data-ga-sermon-slug="{{ $sermon->slug }}"
                    @if($sermonView['thumbnail_url']) poster="{{ $sermonView['thumbnail_url'] }}" @endif>
                    Your browser does not support the <code>video</code> element.
                </video>
                @endif
            </div>
        </div>
        @endif

        {{-- ══ Row 2: Content + Details (two equal-ish columns) ═══ --}}
        <div class="lg:grid lg:grid-cols-5 lg:gap-8 lg:items-start">

            <div class="lg:col-span-3 space-y-6">

                @if ($sermon->show_summary && !empty($sermon->summary))
                <div class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-document-text class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
                            <h2 class="font-display text-xl text-gray-900">Summary</h2>
                        </div>
                        <x-clipboard-button
                            :content="$sermon->summary"
                            hideLabel
                            label="Copy summary"
                            title="Copy summary to clipboard"
                            icon="clipboard-document"
                            size="sm"
                        />
                    </div>
                    <div class="p-6 prose prose-gray max-w-none text-gray-700">
                        {{ $sermon->summary }}
                    </div>
                </div>
                @endif

                @if ($sermon->show_points && !empty($sermon->points) && is_array($sermon->points))
                <div class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-list-bullet class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
                            <h2 class="font-display text-xl text-gray-900">Sermon outline</h2>
                        </div>
                        <x-clipboard-button
                            :content="$sermonView['plain_text_outline']"
                            hideLabel
                            label="Copy outline"
                            title="Copy outline to clipboard"
                            icon="clipboard-document"
                            size="sm"
                        />
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

                @if (! empty($sermonView['has_transcript']) && ! empty($sermonView['transcript_url']))
                <div
                    x-data="{
                        expanded: false,
                        loaded: false,
                        loading: false,
                        error: null,
                        html: '',
                        async toggle() {
                            this.expanded = !this.expanded;
                            if (this.expanded && !this.loaded && !this.loading) {
                                this.loading = true;
                                this.error = null;
                                try {
                                    const response = await fetch(@js($sermonView['transcript_url']), { credentials: 'same-origin' });
                                    if (!response.ok) {
                                        throw new Error('Transcript request failed: ' + response.status);
                                    }
                                    this.html = await response.text();
                                    this.loaded = true;
                                    if (window.gaTrackEvent) {
                                        window.gaTrackEvent('transcript_download', { sermon_slug: @js($sermon->slug) });
                                    }
                                } catch (e) {
                                    this.error = 'Transcript could not be loaded.';
                                } finally {
                                    this.loading = false;
                                }
                            }
                        },
                        plainText() {
                            const tmp = document.createElement('div');
                            tmp.innerHTML = this.html;
                            return (tmp.textContent || tmp.innerText || '').trim();
                        },
                    }"
                    class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-document-text class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
                            <h2 class="font-display text-xl text-gray-900">Automated transcript</h2>
                            <span class="text-xs text-gray-500 font-sans">(may contain errors)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div x-show="loaded" x-cloak>
                                <x-clipboard-button
                                    js-content="plainText()"
                                    label="Copy transcript"
                                    icon="clipboard-document"
                                    size="sm"
                                />
                            </div>

                            <button
                                type="button"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900 border border-gray-200 hover:border-gray-300 rounded-md bg-white transition-colors focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2"
                                @click="toggle()"
                                x-bind:aria-expanded="expanded"
                                x-bind:aria-label="expanded ? 'Hide transcript' : 'Show transcript'"
                                aria-controls="transcript-content">
                                <span x-text="expanded ? 'Hide' : 'Show'" aria-hidden="true">Show</span>
                                <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform duration-200" x-bind:class="expanded ? 'rotate-180' : ''" aria-hidden="true" />
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
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1"
                        class="p-6 max-h-96 overflow-y-auto">
                        <div x-show="loading" class="text-sm text-gray-500">Loading transcript…</div>
                        <div x-show="error" x-text="error" class="text-sm text-red-600"></div>
                        <div x-show="loaded" x-html="html" class="prose prose-gray max-w-none text-gray-700"></div>
                    </div>
                </div>
                @endif

            </div>

            <div class="mt-6 lg:mt-0 lg:col-span-2 space-y-6 lg:sticky lg:top-6">

                <div class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
                    <div class="h-1 w-full bg-[linear-gradient(90deg,var(--color-cbc-teal-light)_0%,var(--color-cbc-teal)_55%,var(--color-cbc-teal-dark)_100%)]"></div>
                    <dl class="p-6 space-y-4">

                        @if ($sermon->date != null)
                        <div class="flex items-center gap-3">
                            <x-heroicon-s-calendar class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
                            <div>
                                <dt class="sr-only">Date</dt>
                                <dd class="text-gray-900 font-medium">
                                    <time datetime="{{ $sermon->date->toDateString() }}">
                                        {{ $sermon->date->format('j F Y') }}
                                    </time>
                                </dd>
                            </div>
                        </div>
                        @endif

                        @if ($sermonView['formatted_duration'])
                        <div class="flex items-center gap-3">
                            <x-heroicon-o-play-circle class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
                            <div>
                                <dt class="sr-only">Duration</dt>
                                <dd class="text-gray-900 font-medium">{{ $sermonView['formatted_duration'] }}</dd>
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

                        @if ($sermonView['preacher_name'] != null)
                        <div class="flex items-center gap-3">
                            <x-heroicon-o-user class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
                            <div>
                                <dt class="sr-only">Preacher</dt>
                                <dd class="text-gray-900 font-medium">
                                    <a href="{{ $sermonView['preacher_url'] }}" wire:navigate class="text-cbc-teal-dark hover:text-cbc-teal transition-colors underline underline-offset-2 decoration-cbc-teal/40">{{ $sermonView['preacher_name'] }}</a>
                                </dd>
                            </div>
                        </div>
                        @endif

                        @if ($sermon->series != null && $sermonView['series_url'])
                        <div class="flex items-center gap-3">
                            <x-heroicon-o-tag class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
                            <div>
                                <dt class="sr-only">Series</dt>
                                <dd class="text-gray-900 font-medium">
                                    <a href="{{ $sermonView['series_url'] }}" wire:navigate class="text-cbc-teal-dark hover:text-cbc-teal transition-colors underline underline-offset-2 decoration-cbc-teal/40">{{ $sermon->series }}</a>
                                </dd>
                            </div>
                        </div>
                        @endif

                    </dl>
                </div>

                @if ($displayReference != null)
                <div
                    x-data="{ expanded: false }"
                    class="rounded-xl bg-white border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            <x-heroicon-o-book-open class="h-4 w-4 text-cbc-teal flex-shrink-0" aria-hidden="true" />
                            <div class="flex items-center gap-2">
                                <div>
                                    @if (! empty($readingReference))
                                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cbc-teal-dark/75 mb-0.5">Passage</p>
                                    @endif
                                    <span class="font-display text-xl text-gray-900">{{ $displayReference }}</span>
                                </div>
                                <x-clipboard-button
                                    :content="$displayReference"
                                    hideLabel
                                    label="Copy reference"
                                    title="Copy Bible reference to clipboard"
                                    icon="clipboard-document"
                                    analytics="scripture_reference"
                                />
                            </div>
                        </div>
                        @if ($sermon->scripturePassage || ! empty($readingReference))
                            <button
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900 border border-gray-200 hover:border-gray-300 rounded-md bg-white transition-colors focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2"
                                @click="expanded = !expanded"
                                x-bind:aria-expanded="expanded"
                                x-bind:aria-label="expanded ? 'Hide passage' : 'Read passage'"
                                aria-controls="passage-content">
                                <span x-text="expanded ? 'Hide' : 'Read passage'" aria-hidden="true">Show passage</span>
                                <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform duration-200" x-bind:class="expanded ? 'rotate-180' : ''" aria-hidden="true" />
                            </button>
                        @endif
                    </div>

                    <div
                        id="passage-content"
                        x-show="expanded"
                        x-cloak
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 -translate-y-1">
                        @if ($sermon->scripturePassage)
                            <div class="p-6">
                                <div
                                    class="scripture-passage prose prose-sm prose-gray max-w-none text-gray-700 leading-relaxed"
                                    data-fums-token="{{ $sermon->scripturePassage->fums_token }}">
                                    {!! $sermon->scripturePassage->html_content !!}
                                </div>
                                <p class="mt-2 text-xs text-gray-500">{{ $sermon->scripturePassage->copyright }}</p>
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

                @if (auth()->user()?->canAccessAdmin())
                <div>

                    @if ($sermon->isFromLivestream() && $sermon->livestreamProcessing)
                    <div class="mb-4 p-4 bg-gray-50 border border-gray-200 rounded-xl">
                        <h2 class="font-display text-lg text-gray-900 mb-3 flex items-center gap-2">
                            <x-heroicon-o-signal class="h-4 w-4 text-cbc-teal" />
                            Livestream processing
                        </h2>
                        <div class="grid grid-cols-1 gap-4 text-sm">
                            <div>
                                <span class="font-medium text-gray-700">Original file:</span>
                                <span class="text-gray-600"> {{ $sermon->livestreamProcessing->original_filename }}</span>
                            </div>
                            <div>
                                <span class="font-medium text-gray-700">Processing date:</span>
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
                                <span class="font-medium text-gray-700">Total segments:</span>
                                <span class="text-gray-600"> {{ $sermon->livestreamProcessing->segments_count ?? 0 }}</span>
                            </div>
                            @if ($sermon->livestreamProcessing->duration)
                            <div>
                                <span class="font-medium text-gray-700">Total duration:</span>
                                <span class="text-gray-600"> {{ gmdate('H:i:s', (int) $sermon->livestreamProcessing->duration) }}</span>
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

                    <form method="POST" action="{{ route('sermons.destroy', $sermon->slug) }}" accept-charset="UTF-8" class="grid grid-cols-2">
                        @csrf
                        <a href="{{ route('admin.sermons.edit', $sermon->slug) }}" wire:navigate
                           class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-bl-md bg-cbc-pattern bg-size-cover focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2 transition-all">
                            <div class="flex items-center justify-center">
                                <x-heroicon-s-pencil-square class="h-6 w-6 mr-2" />
                                Edit
                            </div>
                        </a>
                        <button type="submit"
                                onclick="return confirm('Are you sure you want to delete this sermon? This action cannot be undone.')"
                                class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-br-md bg-gradient-to-r from-rose-600 to-rose-700 focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2 transition-all">
                            <div class="flex items-center justify-center">
                                <x-heroicon-s-trash class="h-6 w-6 mr-2" />
                                Delete
                            </div>
                        </button>
                    </form>
                </div>
                @endif

            </div>

        </div>
    </article>
</x-page.shell>
@endsection
