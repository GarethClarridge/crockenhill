@extends('layouts.main')

@section('title', 'Christmas')

@section('meta_description', 'Celebrate Christmas at Crockenhill Baptist Church. Join us for Carols by Candlelight, our Christmas morning service, and more festive events.')

@section('canonical')
<link rel="canonical" href="{{ url('/christmas') }}">
@endsection

@section('meta_tags')
<x-meta-tags
    title="Christmas"
    description="Celebrate Christmas at Crockenhill Baptist Church. Join us for Carols by Candlelight, our Christmas morning service, and more festive events."
    :image="asset('/images/homepage/christmas2023.webp')"
/>
<x-schema.webpage
    heading="Christmas"
    description="Celebrate Christmas at Crockenhill Baptist Church. Join us for Carols by Candlelight, our Christmas morning service, and more festive events."
    :image="asset('/images/homepage/christmas2023.webp')"
/>

<x-breadcrumbs area="christ" heading="Christmas" jsonOnly />

@php
    $events = [
        [
            'name' => 'Preparing Room',
            'startDate' => '2024-11-30T15:00:00',
            'endDate' => '2024-11-30T18:00:00',
        ],
        [
            'name' => 'Coffee Cup Carols',
            'startDate' => '2024-12-12T10:30:00',
        ],
        [
            'name' => 'Carols in the Chequers',
            'startDate' => '2024-12-18T19:30:00',
            'location' => 'The Chequers, Crockenhill',
        ],
        [
            'name' => 'Carols by Candlelight',
            'startDate' => '2024-12-22T18:00:00',
        ],
        [
            'name' => 'Christmas Morning Service',
            'startDate' => '2024-12-25T10:30:00',
        ],
    ];

    $itemList = [
        '@' . 'context' => 'https://schema.org',
        '@type' => 'ItemList',
        'itemListElement' => array_map(function ($event, $index) {
            $data = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'item' => [
                    '@type' => 'Event',
                    '@id' => url()->current() . '#event-' . \Illuminate\Support\Str::slug($event['name']),
                    'name' => $event['name'],
                    'startDate' => $event['startDate'],
                    'location' => (function() use ($event) {
                        $isOffsite = isset($event['location']);
                        $location = [
                            '@type' => 'Place',
                            'name' => $event['location'] ?? config('church.name'),
                        ];

                        if (! $isOffsite) {
                            $location['address'] = [
                                '@type' => 'PostalAddress',
                                'streetAddress' => config('church.address.street'),
                                'addressLocality' => config('church.address.locality'),
                                'addressRegion' => config('church.address.region'),
                                'postalCode' => config('church.address.postal_code'),
                                'addressCountry' => config('church.address.country'),
                            ];
                            $location['geo'] = [
                                '@type' => 'GeoCoordinates',
                                'latitude' => config('church.geo.latitude'),
                                'longitude' => config('church.geo.longitude'),
                            ];
                        }

                        return $location;
                    })(),
                    'image' => asset('/images/homepage/christmas2023.webp'),
                    'organizer' => [
                        '@type' => 'Organization',
                        'name' => config('church.name'),
                        'url' => url('/'),
                    ],
                ],
            ];
            if (isset($event['endDate'])) {
                $data['item']['endDate'] = $event['endDate'];
            }
            return $data;
        }, $events, array_keys($events)),
    ];
@endphp

<script type="application/ld+json">
{!! json_encode($itemList, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@stop

@section('content')
<main id="main-content" tabindex="-1" class="-mb-10 bg-size-cover bg-center bg-[url('/images/homepage/christmas2023.webp')] bg-gray-700 bg-blend-multiply">

  <x-h1>
    <span class="text-white">Christmas at Crockenhill Baptist Church</span>
  </x-h1>

  <x-text>
    <p class="text-white">
      Celebrate the birth of Jesus Christ with us this Christmas.
    </p>
  </x-text>

  <div class="mx-auto max-w-screen-xl text-center pt-24 pb-12">
    <h2 class="sr-only">Events</h2>
    <h3 id="event-preparing-room" class="font-display text-white mt-8 text-xl lg:text-2xl sm:px-16 lg:px-48">
      Preparing Room
    </h3>
    <p class="mb-8 text-lg text-white font-normal lg:text-xl sm:px-16 lg:px-48">
      Saturday 30th November, 3:00pm – 6:00pm
    </p>
    <h3 id="event-coffee-cup-carols" class="font-display text-white mt-8 text-xl lg:text-2xl sm:px-16 lg:px-48">
      Coffee Cup Carols
    </h3>
    <p class="mb-8 text-lg text-white font-normal lg:text-xl sm:px-16 lg:px-48">
      Thursday 12th December, 10:30am
    </p>
    <h3 id="event-carols-in-the-chequers" class="font-display text-white mt-8 text-xl lg:text-2xl sm:px-16 lg:px-48">
      Carols in the Chequers
    </h3>
    <p class="mb-8 text-lg text-white font-normal lg:text-xl sm:px-16 lg:px-48">
      Wednesday 18th December, 7:30pm
    </p>
    <h3 id="event-carols-by-candlelight" class="font-display text-white mt-8 text-xl lg:text-2xl sm:px-16 lg:px-48">
      Carols by Candlelight
    </h3>
    <p class="mb-8 text-lg text-white font-normal lg:text-xl sm:px-16 lg:px-48">
      Sunday 22nd December, 6:00pm
    </p>
    <h3 id="event-christmas-morning-service" class="font-display text-white mt-8 text-xl lg:text-2xl sm:px-16 lg:px-48">
      Christmas Morning Service
    </h3>
    <p class="mb-8 text-lg text-white font-normal lg:text-xl sm:px-16 lg:px-48">
      Wednesday 25th December, 10:30am
    </p>
  </div>

</main>

@stop