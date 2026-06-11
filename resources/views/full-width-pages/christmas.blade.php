@extends('layouts.main')

@section('title', 'Christmas')

@section('meta_description', 'Celebrate Christmas at Crockenhill Baptist Church. Join us for Carols by Candlelight, our Christmas morning service, and more festive events.')

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
                    'location' => [
                        '@type' => 'Place',
                        'name' => $event['location'] ?? config('organization.name'),
                        'address' => [
                            '@type' => 'PostalAddress',
                            'streetAddress' => config('organization.address.street'),
                            'addressLocality' => config('organization.address.locality'),
                            'addressRegion' => config('organization.address.region'),
                            'postalCode' => config('organization.address.postal_code'),
                            'addressCountry' => config('organization.address.country'),
                        ],
                    ],
                    'image' => asset('/images/homepage/christmas2023.webp'),
                    'organizer' => [
                        '@type' => 'Organization',
                        'name' => config('organization.name'),
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
    @foreach ($events as $event)
        <h3 id="event-{{ \Illuminate\Support\Str::slug($event['name']) }}" class="font-display text-white mt-8 text-xl lg:text-2xl sm:px-16 lg:px-48">
          {{ $event['name'] }}
        </h3>
        <p class="mb-8 text-lg text-white font-normal lg:text-xl sm:px-16 lg:px-48">
          {{-- Note: Static display strings below match the hardcoded design requirements while linking to dynamic schema data above --}}
          @if ($event['name'] === 'Preparing Room')
            Saturday 30th November, 3-6pm
          @elseif ($event['name'] === 'Coffee Cup Carols')
            Thursday 12th, 10:30am
          @elseif ($event['name'] === 'Carols in the Chequers')
            Wednesday 18th, 7:30pm
          @elseif ($event['name'] === 'Carols by Candlelight')
            Sunday 22nd, 6:00pm
          @elseif ($event['name'] === 'Christmas Morning Service')
            Wednesday 25th, 10:30am
          @endif
        </p>
    @endforeach
  </div>

</main>

@stop