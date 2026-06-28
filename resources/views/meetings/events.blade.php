@extends('layouts.main')

@section('content')
<x-page.shell
    :heading="$heading"
    :description="$description ?? null"
    :metaDescription="$metaDescription ?? $description"
    :headingpicture="$headingpicture ?? null"
    :headingpicture-mobile="$headingpictureMobile ?? null"
    :headingpicture-tablet="$headingpictureTablet ?? null"
    :area="$area ?? null"
    :slug="$slug ?? null"
    :links="$links ?? []"
    :meta-tags="false"
>
    @push('meta_tags')
        <x-meta-tags
            :title="$heading"
            :description="$description"
            :image="$headingpicture ?? null"
            :image-alt="'Events for ' . ($heading ?? $meeting->slug)"
        />
        <x-schema.webpage
            :heading="$heading"
            :description="$description"
        />

        @if($schemaEvents->isNotEmpty())
            <script type="application/ld+json">
                {!! json_encode([
                    '@' . 'context' => 'https://schema.org',
                    '@type' => 'ItemList',
                    'itemListElement' => $schemaEvents->values()->map(function ($event, $index) use ($meeting) {
                        $eventData = [
                            '@type' => 'ListItem',
                            'position' => $index + 1,
                            'item' => [
                                '@type' => 'Event',
                                'name' => $event->title,
                                'description' => \Illuminate\Support\Str::limit(strip_tags((string) ($event->description ?? 'Church event at Crockenhill Baptist Church')), 150),
                                'startDate' => $event->start_datetime->toIso8601String(),
                                'location' => [
                                    '@type' => 'Place',
                                    'name' => $event->location ?? ($meeting->location ?? 'Crockenhill Baptist Church'),
                                    'address' => [
                                        '@type' => 'PostalAddress',
                                        'streetAddress' => config('organization.address.street'),
                                        'addressLocality' => config('organization.address.locality'),
                                        'addressRegion' => config('organization.address.region'),
                                        'postalCode' => config('organization.address.postal_code'),
                                        'addressCountry' => config('organization.address.country'),
                                    ],
                                ],
                                'image' => asset('images/Primary.png'),
                                'organizer' => [
                                    '@type' => 'Organization',
                                    'name' => config('organization.name'),
                                    'url' => url('/'),
                                ],
                            ],
                        ];

                        if ($event->end_datetime) {
                            $eventData['item']['endDate'] = $event->end_datetime->toIso8601String();
                        }

                        return $eventData;
                    })->all(),
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
            </script>
        @endif
    @endpush

    @if (isset($content))
        <div class="mt-6 prose lg:prose-xl">
            {!! $content !!}
        </div>
    @endif

    <div class="prose max-w-none mb-8">
        <p>All meetings for <strong>{{ $meeting->heading ?? $meeting->slug }}</strong> from our calendar.</p>
        <p><a href="{{ route('meetings.show', $meeting) }}" wire:navigate class="text-blue-600 hover:underline">&larr; Back to {{ $meeting->heading }}</a></p>
    </div>

    @if($upcomingEvents->isNotEmpty() || $pastEvents->isNotEmpty())
        @if($upcomingEvents->isNotEmpty())
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Upcoming meetings</h2>
                <div class="space-y-4">
                    @foreach($upcomingEvents as $event)
                        <x-calendar-event-card
                            :event="$event"
                            :meeting="$meeting"
                            variant="admin"
                            :show-meeting-badge="false"
                            date-format="l, j F Y"
                        />
                    @endforeach
                </div>
            </div>
        @endif

        @if($pastEvents->isNotEmpty())
            <div>
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Past meetings</h2>
                <div class="space-y-3">
                    @foreach($pastEvents as $event)
                        <x-calendar-event-card
                            :event="$event"
                            :meeting="$meeting"
                            variant="compact"
                            :show-meeting-badge="false"
                            date-format="j M Y"
                        />
                    @endforeach

                    @if($hasMorePastEvents)
                        <p class="text-sm text-gray-500 text-center">Showing {{ $pastEventsLimit }} most recent past meetings</p>
                    @endif
                </div>
            </div>
        @endif
    @else
        <div class="text-center py-12">
            <x-heroicon-o-calendar class="mx-auto h-12 w-12 text-gray-400" />
            <h3 class="mt-2 text-sm font-medium text-gray-900">No meetings found</h3>
            <p class="mt-1 text-sm text-gray-500">No meetings have been scheduled for this meeting yet.</p>
        </div>
    @endif
</x-page.shell>
@endsection
