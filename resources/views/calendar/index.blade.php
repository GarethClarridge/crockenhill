@extends('layouts.main')

@section('content')
<x-page.shell
    :heading="$heading ?? 'Church Calendar'"
    :description="$description ?? 'Upcoming events at Crockenhill Baptist Church.'"
    :metaDescription="$metaDescription ?? 'Upcoming events at Crockenhill Baptist Church.'"
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
            title="Church Calendar"
            description="Upcoming events at Crockenhill Baptist Church."
            :image="asset('/images/homepage/may2024wide.webp')"
            image-alt="Crockenhill Baptist Church members outside the church building"
            label1="Upcoming events"
            :data1="$allEvents->count()"
        />
        <x-schema.webpage
            heading="Church Calendar"
            description="Upcoming events at Crockenhill Baptist Church."
            :image="asset('/images/homepage/may2024wide.webp')"
        />

        @if($allEvents->isNotEmpty())
            {{--
                Performance Optimization: Resolve organization config and asset values once
                outside the loop to avoid redundant helper calls for every event in the list.
            --}}
            @php
                $orgName = config('organization.name');
                $orgUrl = url('/');
                $orgStreet = config('organization.address.street');
                $orgLocality = config('organization.address.locality');
                $orgRegion = config('organization.address.region');
                $orgPostalCode = config('organization.address.postal_code');
                $orgCountry = config('organization.address.country');
                $orgLatitude = config('organization.geo.latitude');
                $orgLongitude = config('organization.geo.longitude');
                $primaryImage = asset('images/Primary.png');
                $currentUrl = request()->url();
            @endphp
            <script type="application/ld+json">
                {!! json_encode([
                    '@' . 'context' => 'https://schema.org',
                    '@type' => 'ItemList',
                    'itemListElement' => $allEvents->map(function ($event, $index) use ($orgName, $orgUrl, $orgStreet, $orgLocality, $orgRegion, $orgPostalCode, $orgCountry, $orgLatitude, $orgLongitude, $primaryImage, $currentUrl) {
                        $eventData = [
                            '@type' => 'ListItem',
                            'position' => $index + 1,
                            'item' => [
                                '@type' => 'Event',
                                '@id' => $currentUrl . '#event-' . $event->id,
                                'name' => $event->title,
                                'description' => \Illuminate\Support\Str::limit(strip_tags($event->description ?? "Church event at {$orgName}"), 150),
                                'startDate' => $event->start_datetime->toIso8601String(),
                                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                                'eventStatus' => 'https://schema.org/EventScheduled',
                                'location' => (function() use ($event, $orgName, $orgStreet, $orgLocality, $orgRegion, $orgPostalCode, $orgCountry, $orgLatitude, $orgLongitude) {
                                    $rawLocation = $event->location ?? $event->meeting?->location;
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
                                    '@id' => config('app.url').'/#organization',
                                ],
                                'offers' => [
                                    '@type' => 'Offer',
                                    'url' => url()->current(),
                                    'price' => '0',
                                    'priceCurrency' => 'GBP',
                                    'availability' => 'https://schema.org/InStock',
                                ],
                            ],
                        ];

                        if ($event->end_datetime) {
                            $eventData['item']['endDate'] = $event->end_datetime->toIso8601String();
                        }

                        return $eventData;
                    })->values()->all(),
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
        <p>Here are all upcoming events from our church calendar. Events are automatically synchronised from our Google Calendar.</p>
    </div>

    @if($allEvents->count() > 0)
        <div class="space-y-6">
            @foreach($allEvents->groupBy(function($event) { return $event->start_datetime->format('Y-m-d'); }) as $date => $events)
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">
                            <time datetime="{{ $date }}">
                                {{ \Carbon\Carbon::parse($date)->format('l, j F Y') }}
                            </time>
                        </h3>
                    </div>

                    <div class="divide-y divide-gray-100">
                        @foreach($events as $event)
                            <x-calendar-event-card
                                id="event-{{ $event->id }}"
                                class="px-6 py-4 hover:bg-gray-50 transition-colors"
                                :event="$event"
                                variant="list"
                                :show-date="false"
                                date-format="l, j F Y"
                                description-limit="150"
                            />
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-12">
            <x-heroicon-o-calendar class="mx-auto h-12 w-12 text-gray-400" />
            <h3 class="mt-2 text-sm font-medium text-gray-900">No upcoming events</h3>
            <p class="mt-1 text-sm text-gray-500">Check back soon for new events.</p>
        </div>
    @endif

    @if($allEvents->count() >= 50)
        <div class="mt-8 text-center">
            <p class="text-sm text-gray-600">Showing next 50 events. More events may be available.</p>
        </div>
    @endif
</x-page.shell>
@endsection
