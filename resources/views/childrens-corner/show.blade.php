@extends('layouts.page')

@section('meta_tags')
    <x-meta-tags
        :title="$sermon->title"
        :description="$sermon->meta_description ?: $sermon->title"
        type="article"
        :image="$sermonView['thumbnail_url']"
        :image-width="$sermonView['thumbnail_url'] ? 1280 : 800"
        :image-height="$sermonView['thumbnail_url'] ? 720 : 600"
        :image-alt="\"Children's Corner: {$sermon->title}\""
        :audio="$sermonView['audio_url']"
        :video="$sermonView['video_url']"
        :canonical="$sermonView['public_url']"
    />
@endsection

@section('dynamic_content')
    @php
        $speakerName = $sermon->displayPreacherName();
        $hasAudio = filled($sermon->audio_file_path);
        $hasVideo = filled($sermon->video_file_path);
    @endphp

    <section class="space-y-8">
        <div class="overflow-hidden rounded-2xl border border-cbc-teal/15 bg-[linear-gradient(135deg,rgba(36,154,151,0.12)_0%,rgba(29,104,106,0.08)_50%,rgba(20,85,87,0.16)_100%)] p-8 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-6">
                <div class="space-y-5">
                    <div class="space-y-2">
                        <p class="text-sm font-semibold uppercase tracking-[0.3em] text-cbc-teal-dark/75">Children's Corner</p>
                        <p class="max-w-2xl text-lg text-gray-700">
                            A shorter talk for children from the church family, kept simple and easy to play back.
                        </p>
                    </div>

                    <dl class="grid gap-4 text-sm text-gray-700 sm:grid-cols-2">
                        <div class="flex items-center gap-3">
                            <x-heroicon-s-calendar class="h-5 w-5 shrink-0 text-cbc-teal" aria-hidden="true" />
                            <div>
                                <dt class="sr-only">Date</dt>
                                <dd>{{ $sermon->date->format('j F Y') }}</dd>
                            </div>
                        </div>

                        @if (filled($speakerName))
                            <div class="flex items-center gap-3">
                                <x-heroicon-o-user class="h-5 w-5 shrink-0 text-cbc-teal" aria-hidden="true" />
                                <div>
                                    <dt class="sr-only">Speaker</dt>
                                    <dd>{{ $speakerName }}</dd>
                                </div>
                            </div>
                        @endif
                    </dl>

                    <div class="flex flex-wrap gap-2" aria-label="Media availability">
                        @if ($hasVideo)
                            <span class="inline-flex items-center gap-1 rounded-full bg-cbc-teal/10 px-3 py-1 text-xs font-semibold text-cbc-teal-dark">
                                <x-heroicon-o-video-camera class="h-4 w-4" aria-hidden="true" />
                                Video available
                            </span>
                        @endif
                        @if ($hasAudio)
                            <span class="inline-flex items-center gap-1 rounded-full bg-cbc-teal/10 px-3 py-1 text-xs font-semibold text-cbc-teal-dark">
                                <x-heroicon-o-speaker-wave class="h-4 w-4" aria-hidden="true" />
                                Audio available
                            </span>
                        @endif
                    </div>
                </div>

                <div class="w-full max-w-sm">
                    <div class="w-full rounded-xl bg-[linear-gradient(120deg,theme(colors.cbc-teal.light)_0%,theme(colors.cbc-teal.DEFAULT)_55%,theme(colors.cbc-teal.dark)_100%)] p-[1.5px]">
                        <x-button link="{{ route('childrens-corner.index') }}" variant="secondary" size="lg" class="w-full rounded-[11px]">
                            Back to Children's Corner
                        </x-button>
                    </div>
                </div>
            </div>
        </div>

        @if ($hasVideo)
            <x-card heading="Watch">
                <div class="not-prose">
                    <video
                        src="{{ $sermonView['video_url'] }}"
                        class="w-full rounded-xl bg-slate-950"
                        controls
                        @if($sermonView['thumbnail_url']) poster="{{ $sermonView['thumbnail_url'] }}" @endif
                    >
                        Your browser does not support the <code>video</code> element.
                    </video>
                </div>
            </x-card>
        @endif

        @if ($hasAudio)
            <x-card heading="Listen">
                <div class="not-prose">
                    <audio src="{{ $sermonView['audio_url'] }}" class="w-full" controls>
                        Your browser does not support the <code>audio</code> element.
                    </audio>
                </div>
            </x-card>
        @endif

        @if (! $hasAudio && ! $hasVideo)
            <x-card heading="Media coming soon">
                <p>This talk has been published, but its media files are not available yet. Please check back soon.</p>
            </x-card>
        @endif
    </section>
@endsection
