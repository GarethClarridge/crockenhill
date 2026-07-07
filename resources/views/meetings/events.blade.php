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
            {{--
                Performance Optimization: Resolve organization config and asset values once
                outside the loop to avoid redundant helper calls for every event in the list.
            --}}
            @php
                $orgName = (string) config('organization.name');
                $orgUrl = url('/');
                $orgStreet = (string) config('organization.address.street');
                $orgLocality = (string) config('organization.address.locality');
                $orgRegion = (string) config('organization.address.region');
                $orgPostalCode = (string) config('organization.address.postal_code');
                $orgCountry = (string) config('organization.address.country');
                $orgLatitude = config('organization.geo.latitude');
                $orgLongitude = config('organization.geo.longitude');
                $primaryImage = asset('images/Primary.png');
                $currentUrl = url()->current();
                $appUrl = (string) config('app.url');
            @endphp
            <script type="application/ld+json">
                {!! json_encode([
                    '@' . 'context' => 'https://schema.org',
                    '@type' => 'ItemList',
                    'itemListElement' => $schemaEvents->values()->map(function ($event, $index) use ($meeting, $orgName, $orgUrl, $orgStreet, $orgLocality, $orgRegion, $orgPostalCode, $orgCountry, $orgLatitude, $orgLongitude, $primaryImage, $currentUrl, $appUrl) {
                        $eventData = [
                            '@type' => 'ListItem',
                            'position' => $index + 1,
                            'item' => [
                                '@type' => 'Event',
                                '@id' => $currentUrl . '#event-' . $event->id,
                                'name' => $event->title,
                                'description' => \Illuminate\Support\Str::limit(strip_tags((string) ($event->description ?? "Church event at {$orgName}")), 150),
                                'startDate' => $event->start_datetime->toIso8601String(),
                                'location' => (function() use ($event, $meeting, $orgName, $orgStreet, $orgLocality, $orgRegion, $orgPostalCode, $orgCountry, $orgLatitude, $orgLongitude) {
                                    $rawLocation = $event->location ?? $meeting->location;
                                    $locName = $rawLocation ?? $orgName;
                                    $isOnsite = blank($rawLocation) || strcasecmp(trim($rawLocation), $orgName) === 0;

                                    $location = [
                                        '@type' => 'Place',
                                        'name' => $locName,
                                    ];

                                    if ($isOnsite) {
                                        $location['address'] = [
                                            '@type' => 'PostalAddress',
                                            'streetAddress' => $orgStreet,
                                            'addressLocality' => $orgLocality,
                                            'addressRegion' => $orgRegion,
                                            'postalCode' => $orgPostalCode,
                                            'addressCountry' => $orgCountry,
                                        ];
                                        $location['geo'] = [
                                            '@type' => 'GeoCoordinates',
                                            'latitude' => $orgLatitude,
                                            'longitude' => $orgLongitude,
                                        ];
                                    }

                                    return $location;
                                })(),
                                'image' => $primaryImage,
                                'organizer' => [
                                    '@type' => 'Organization',
                                    'name' => $orgName,
                                    'url' => $orgUrl,
                                    '@id' => $appUrl . '/#organization',
                                ],
                            ],
                        ];

                        if ($event->end_datetime) {
                            $eventData['item']['endDate'] = $event->end_datetime->toIso8601String();
                        }

                        // This listing includes recent past meetings, so only advertise an
                        // active offer for events that have not yet finished; marking a
                        // concluded event as InStock would be inaccurate structured data.
                        $eventEnd = $event->end_datetime ?? $event->start_datetime;

                        if ($eventEnd->isFuture()) {
                            $eventData['item']['offers'] = [
                                '@type' => 'Offer',
                                'url' => $currentUrl,
                                'price' => '0',
                                'priceCurrency' => 'GBP',
                                'availability' => 'https://schema.org/InStock',
                            ];
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
                            heading-level="h3"
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
                            heading-level="h3"
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
            <p class="mt-1 text-sm text-gray-500">No meetings have been scheduled for this group yet.</p>
        </div>
    @endif
</x-page.shell>
@endsection
