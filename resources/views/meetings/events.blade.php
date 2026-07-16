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
            <p class="mt-1 text-sm text-gray-500">No meetings have been scheduled for this meeting yet.</p>
        </div>
    @endif
</x-page.shell>
@endsection
