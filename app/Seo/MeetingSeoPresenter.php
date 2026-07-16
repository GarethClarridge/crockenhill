<?php

declare(strict_types=1);

namespace App\Seo;

use App\Models\CalendarEvent;
use App\Models\Meeting;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MeetingSeoPresenter
{
    /**
     * @param  Collection<int, CalendarEvent>  $events
     * @return array<string, mixed>|null
     */
    public function eventItemList(
        Meeting $meeting,
        Collection $events,
        string $descriptionFallback,
        ?string $image = null,
        bool $includeFullEventMetadata = true,
    ): ?array {
        if ($events->isEmpty()) {
            return null;
        }

        $organizationName = (string) config('church.name');
        $organizationUrl = url('/');
        $organizationId = (string) config('app.url').'/#organization';
        $primaryImage = asset('images/Primary.png');
        $currentUrl = url()->current();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $events->values()->map(fn (CalendarEvent $event, int $index): array => $this->eventListItem(
                meeting: $meeting,
                event: $event,
                index: $index,
                descriptionFallback: $descriptionFallback,
                image: $image ?? $primaryImage,
                organizationName: $organizationName,
                organizationUrl: $organizationUrl,
                organizationId: $organizationId,
                currentUrl: $currentUrl,
                includeFullEventMetadata: $includeFullEventMetadata,
            ))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventListItem(
        Meeting $meeting,
        CalendarEvent $event,
        int $index,
        string $descriptionFallback,
        string $image,
        string $organizationName,
        string $organizationUrl,
        string $organizationId,
        string $currentUrl,
        bool $includeFullEventMetadata,
    ): array {
        $eventItem = [
            '@type' => 'Event',
            '@id' => $currentUrl.'#event-'.$event->id,
            'name' => $event->title,
            'description' => Str::limit(strip_tags((string) ($event->description ?? $descriptionFallback)), 150),
            'startDate' => $event->start_datetime->toIso8601String(),
        ];

        if ($includeFullEventMetadata) {
            $eventItem['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
            $eventItem['eventStatus'] = 'https://schema.org/EventScheduled';
        }

        $eventItem['location'] = $this->location($meeting, $event, $organizationName);
        $eventItem['image'] = $image;
        $eventItem['organizer'] = [
            '@type' => 'Organization',
            'name' => $organizationName,
            'url' => $organizationUrl,
            '@id' => $organizationId,
        ];

        if ($includeFullEventMetadata) {
            $eventItem['offers'] = $this->offer($currentUrl);
        }

        $eventItem['endDate'] = $event->end_datetime->toIso8601String();

        if (! $includeFullEventMetadata && $event->end_datetime->isFuture()) {
            $eventItem['offers'] = $this->offer($currentUrl);
        }

        return [
            '@type' => 'ListItem',
            'position' => $index + 1,
            'item' => $eventItem,
        ];
    }

    /** @return array<string, mixed> */
    private function location(Meeting $meeting, CalendarEvent $event, string $organizationName): array
    {
        $rawLocation = $event->location ?? $meeting->location;
        $location = [
            '@type' => 'Place',
            'name' => $rawLocation ?? $organizationName,
        ];

        if (! blank($rawLocation) && strcasecmp(trim($rawLocation), $organizationName) !== 0) {
            return $location;
        }

        $location['address'] = [
            '@type' => 'PostalAddress',
            'streetAddress' => (string) config('church.address.street'),
            'addressLocality' => (string) config('church.address.locality'),
            'addressRegion' => (string) config('church.address.region'),
            'postalCode' => (string) config('church.address.postal_code'),
            'addressCountry' => (string) config('church.address.country'),
        ];
        $location['geo'] = [
            '@type' => 'GeoCoordinates',
            'latitude' => config('church.geo.latitude'),
            'longitude' => config('church.geo.longitude'),
        ];

        return $location;
    }

    /** @return array<string, string> */
    private function offer(string $currentUrl): array
    {
        return [
            '@type' => 'Offer',
            'url' => $currentUrl,
            'price' => '0',
            'priceCurrency' => 'GBP',
            'availability' => 'https://schema.org/InStock',
        ];
    }
}
