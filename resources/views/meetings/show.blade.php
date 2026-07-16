@php
    $titleParts = array_filter([
        $heading,
        $meeting->day,
        $meeting->start_time?->format('g:ia'),
    ]);
    $pageTitle = implode(' | ', $titleParts);
@endphp

@extends('layouts.main')

@section('content')
<x-page.shell
    :heading="$heading"
    :description="$description ?? $heading"
    :metaDescription="$metaDescription ?? $description ?? $heading"
    :headingpicture="$headingpicture ?? null"
    :headingpicture-mobile="$headingpictureMobile ?? null"
    :headingpicture-tablet="$headingpictureTablet ?? null"
    :area="$area ?? null"
    :slug="$slug ?? null"
    :links="$links ?? []"
    :title="$pageTitle"
    :meta-tags="false"
>
    @push('meta_tags')
        <x-meta-tags
            :title="$pageTitle"
            :description="$description ?? $heading"
            :image="$headingpicture ?? null"
            :image-alt="'Meeting: ' . $heading"
            label1="When"
            :data1="$meeting->day . ($meeting->start_time ? ' at ' . $meeting->start_time->format('g:ia') : '')"
        />
        <x-schema.webpage
            :heading="$heading"
            :description="$description ?? $heading"
            :image="$headingpicture"
            :datePublished="$meeting->created_at"
            :dateModified="$meeting->updated_at"
        />

        @if($eventSchema !== null)
            <script type="application/ld+json">
                {!! json_encode($eventSchema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
            </script>
        @endif
    @endpush

    @if (isset($content))
        <div class="mt-6 prose lg:prose-xl">
            {!! $content !!}
        </div>
    @endif

    {{-- Meeting Details --}}
    <div class="bg-cbc-pattern bg-size-cover my-12 px-6 md:px-16 py-12 text-white text-3xl font-display">
        <dl>
            @if ($meeting->day != '')
                <div class="my-3 flex items-center md:leading-loose">
                    <x-heroicon-s-calendar class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
                    <dt class="sr-only">Day</dt>
                    <dd>{{ $meeting->day }}</dd>
                </div>
            @endif
            @if ($meeting->start_time != '')
                <div class="my-3 flex items-center md:leading-loose">
                    <x-heroicon-o-clock class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
                    <dt class="sr-only">Time</dt>
                    <dd>
                        <time datetime="{{ $meeting->start_time->format('H:i') }}">
                            {{ $meeting->start_time->format('g:ia') }}
                        </time>
                        @if ($meeting->end_time)
                            &ndash;
                            <time datetime="{{ $meeting->end_time->format('H:i') }}">
                                {{ $meeting->end_time->format('g:ia') }}
                            </time>
                        @endif
                    </dd>
                </div>
            @endif
            @if ($meeting->location != '')
                <div class="my-3 flex items-center leading-relaxed md:leading-loose">
                    <x-heroicon-o-map-pin class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
                    <dt class="sr-only">Location</dt>
                    <dd>{{ $meeting->location }}</dd>
                </div>
            @endif
            @if ($meeting->who != '')
                <div class="my-3 flex items-center leading-relaxed md:leading-loose">
                    <x-heroicon-o-user class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
                    <dt class="sr-only">Who this is for</dt>
                    <dd>{{ $meeting->who }}</dd>
                </div>
            @endif
            @if ($meeting->leaders_phone != '')
                <div class="my-3 flex items-center leading-relaxed md:leading-loose">
                    <x-heroicon-o-phone class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
                    <dt class="sr-only">Phone</dt>
                    <dd>{{ $meeting->leaders_phone }}</dd>
                </div>
            @endif
            @if ($meeting->leaders_email != '')
                <div class="my-3 flex items-center leading-relaxed md:leading-loose">
                    <x-heroicon-o-envelope class="h-10 w-10 mr-2 shrink-0" aria-hidden="true" />
                    <dt class="sr-only">Email</dt>
                    <dd>{{ $meeting->leaders_email }}</dd>
                </div>
            @endif
        </dl>
    </div>

    @if ($photos->isNotEmpty())
        <div class="flex flex-wrap">
            @foreach ($photos as $index => $photo)
                @php
                    $altText = !empty($photo['name']) && !preg_match('/\.(jpe?g|png|webp|gif)$/i', $photo['name'])
                        ? $photo['name']
                        : $heading . ' photo ' . ($index + 1);
                @endphp
                <div class="md:w-1/2 pr-4 pl-4">
                    <img src="{{ $photo['url'] }}" width="100%" alt="{{ $altText }}" loading="lazy">
                </div>
            @endforeach
        </div>
    @endif

    {{-- Calendar Events --}}
    @if(isset($upcomingEvents) && $upcomingEvents->isNotEmpty())
        <hr class="my-8">
        <x-h2>Upcoming meetings</x-h2>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3 mb-6">
            @foreach($upcomingEvents->take(6) as $event)
                <x-calendar-event-card
                    id="event-{{ $event->id }}"
                    :event="$event"
                    :meeting="$meeting"
                    :show-meeting-badge="false"
                    description-limit="80"
                    date-format="j M Y"
                    heading-level="h3"
                />
            @endforeach
        </div>

        @if($upcomingEvents->count() > 6)
            <div class="text-center mb-6">
                <a href="{{ route('meetings.events', $meeting) }}" wire:navigate class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-cbc-teal">
                    View all {{ $upcomingEvents->count() }} upcoming meetings
                    <x-heroicon-o-arrow-right class="ml-2 h-4 w-4" aria-hidden="true" />
                </a>
            </div>
        @endif
    @endif

    {{-- Recent Past Events --}}
    @if(isset($pastEvents) && $pastEvents->isNotEmpty())
        <div class="mb-8">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Recent meetings</h2>
            <div class="space-y-2">
                @foreach($pastEvents->take(3) as $event)
                    <x-calendar-event-compact :event="$event" />
                @endforeach
            </div>

            @if($pastEvents->count() > 3)
                <div class="mt-3">
                    <a href="{{ route('meetings.events', $meeting) }}" wire:navigate class="text-sm text-blue-600 hover:text-blue-500">
                        View all past meetings &rarr;
                    </a>
                </div>
            @endif
        </div>
    @endif

    {{-- Safeguarding --}}
    @if ($meeting->type->value === 'ChildrenAndYoungPeople' || $meeting->slug === 'sunday-mornings')
        <hr>
        <small class="prose">
            All activities at the church are carried out in accordance with our
            <a href="/church/safeguarding-policy" wire:navigate>Safeguarding policy</a>
            and our
            <a href="/media/documents/BehaviourPolicy.pdf">Positive behaviour policy</a>.
        </small>
    @endif
</x-page.shell>
@endsection
