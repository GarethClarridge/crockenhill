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
>
    @if (isset($content))
        <div class="mt-6 prose lg:prose-xl">
            {!! $content !!}
        </div>
    @endif

    <div class="prose max-w-none mb-8">
        <p>These events from our calendar haven't been automatically categorised into a specific meeting type. They may be special events or one-off occasions.</p>
    </div>

    @if($uncategorizedEvents->count() > 0)
        <div class="space-y-4">
            @foreach($uncategorizedEvents as $event)
                <x-calendar-event-card
                    :event="$event"
                    variant="admin"
                    date-format="l, j F Y"
                    heading-level="h2"
                />
            @endforeach
        </div>
    @else
        <div class="text-center py-12">
            <x-heroicon-o-check-circle class="mx-auto h-12 w-12 text-green-400" />
            <h3 class="mt-2 text-sm font-medium text-gray-900">All events are categorised</h3>
            <p class="mt-1 text-sm text-gray-500">Great! All calendar events have been properly categorised.</p>
        </div>
    @endif
</x-page.shell>
@endsection
