@extends('layouts/page')

@section('title', 'Church Calendar')

@section('meta_description', 'Upcoming events at Crockenhill Baptist Church.')

@section('meta_tags')
<x-meta-tags
    title="Church Calendar"
    description="Upcoming events at Crockenhill Baptist Church."
/>

{{-- JSON-LD Events --}}
@if($allEvents->isNotEmpty())
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'itemListElement' => $allEvents->map(function ($event, $index) {
        $eventData = [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'item' => [
                '@type' => 'Event',
                'name' => $event->title,
                'description' => \Illuminate\Support\Str::limit(strip_tags($event->description ?? 'Church event at Crockenhill Baptist Church'), 150),
                'startDate' => $event->start_datetime->toIso8601String(),
                'location' => [
                    '@type' => 'Place',
                    'name' => $event->location ?? ($event->meeting?->location ?? 'Crockenhill Baptist Church'),
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
    })->values()->all(),
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
@endif
@endsection

@section('dynamic_content')

<div class="prose max-w-none mb-8">
  <p>Here are all upcoming events from our church calendar. Events are automatically synchronized from our Google Calendar.</p>
</div>

@if($allEvents->count() > 0)
  <div class="space-y-6">
    @foreach($allEvents->groupBy(function($event) { return $event->start_datetime->format('Y-m-d'); }) as $date => $events)
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="bg-gray-50 px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">
            <time datetime="{{ $date }}">
              {{ \Carbon\Carbon::parse($date)->format('l, F j, Y') }}
            </time>
          </h3>
        </div>
        
        <div class="divide-y divide-gray-100">
          @foreach($events as $event)
            <div class="px-6 py-4 hover:bg-gray-50 transition-colors">
              <x-calendar-event-card 
                :event="$event" 
                variant="list"
                :show-date="false"
                date-format="l, F j, Y"
                description-limit="150"
              />
            </div>
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

@stop