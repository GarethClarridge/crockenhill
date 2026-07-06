@props([
    'sermon',
    'cardThumbnailUrl',
    'speakerName',
    'hasAudio',
    'hasVideo',
])

<article class="group relative flex h-full flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-all duration-300 hover:-translate-y-1 hover:shadow-lg">
    @if ($cardThumbnailUrl)
        <a
            href="{{ route('childrens-corner.show', ['sermon' => $sermon->slug]) }}"
            wire:navigate
            class="relative z-10 block aspect-video overflow-hidden border-b border-gray-100 bg-slate-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 after:absolute after:inset-0"
        >
            <img
                src="{{ $cardThumbnailUrl }}"
                alt=""
                role="presentation"
                class="h-full w-full object-cover brightness-110 contrast-105 transition duration-500 ease-out group-hover:scale-105 group-hover:brightness-115"
                loading="lazy"
            >
            <div class="absolute inset-0 bg-gradient-to-t from-black/45 via-black/10 to-transparent"></div>
            <div class="absolute inset-x-5 bottom-5">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-white/90">Children's Corner</p>
                <h2 class="mt-2 font-display text-2xl leading-tight text-white [text-shadow:0_2px_8px_rgba(0,0,0,0.45)]">
                    {{ $sermon->title }}
                </h2>
            </div>
        </a>
    @endif

    <div class="flex flex-1 flex-col gap-5 p-6">
        @unless ($cardThumbnailUrl)
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cbc-teal-dark/80">Children's Corner</p>
                <a
                    href="{{ route('childrens-corner.show', ['sermon' => $sermon->slug]) }}"
                    wire:navigate
                    class="relative z-10 block rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 after:absolute after:inset-0"
                >
                    <h2 class="font-display text-2xl leading-tight text-gray-900 transition-colors hover:text-cbc-teal-dark">
                        {{ $sermon->title }}
                    </h2>
                </a>
            </div>
        @endunless

        <dl class="space-y-3 text-sm text-gray-700">
            <div class="flex items-center gap-3">
                <x-heroicon-s-calendar class="h-5 w-5 shrink-0 text-cbc-teal" aria-hidden="true" />
                <div>
                    <dt class="sr-only">Date</dt>
                    <dd>
                        <time datetime="{{ $sermon->date->toDateString() }}">
                            {{ $sermon->date->format('j F Y') }}
                        </time>
                    </dd>
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

        <div class="mt-auto space-y-4">
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
                @if (! $hasAudio && ! $hasVideo)
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                        Media coming soon
                    </span>
                @endif
            </div>

            <x-button
                link="{{ route('childrens-corner.show', ['sermon' => $sermon->slug]) }}"
                variant="secondary"
                size="sm"
                inline
                tabindex="-1"
                aria-hidden="true"
            >
                Open talk
            </x-button>
        </div>
    </div>
</article>
