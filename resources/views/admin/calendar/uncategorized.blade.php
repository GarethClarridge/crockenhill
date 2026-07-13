@extends('layouts.main')

@section('content')
<x-admin.shell heading="Categorise calendar events">

<x-admin.page
    title="Uncategorised events"
    description="These events couldn't be automatically categorised. Please assign them to the appropriate meeting type."
>
    <x-slot:actions>
        <x-button link="{{ route('admin.calendar.patterns') }}" variant="outline" inline>
            View patterns
        </x-button>
        <form method="POST" action="{{ route('admin.calendar.sync') }}" class="inline">
            @csrf
            <x-form-button variant="secondary" inline>
                Sync calendar
            </x-form-button>
        </form>
    </x-slot:actions>

    @if($uncategorizedEvents->isNotEmpty())
        <div class="space-y-6">
            @foreach($uncategorizedEvents as $event)
                <x-calendar-event-card
                    :event="$event"
                    variant="admin"
                    date-format="l, j F Y"
                    description-limit="200"
                    heading-level="h2"
                >
                    <div class="flex-shrink-0 ml-6">
                        <form method="POST" action="{{ route('admin.calendar.categorize') }}" class="flex items-center gap-2">
                            @csrf
                            <input type="hidden" name="event_id" value="{{ $event->id }}">

                            <x-select
                                name="meeting_slug"
                                placeholder="Select meeting type..."
                                :options="$meetings->map(fn($m) => ['id' => $m->slug, 'name' => $m->slug])->values()->toArray()"
                                required
                                class="w-56"
                            />

                            <x-form-button type="submit" variant="primary" size="sm">
                                Categorise event
                            </x-form-button>
                        </form>
                    </div>
                </x-calendar-event-card>
            @endforeach
        </div>
    @else
        <div class="text-center py-12">
            <x-heroicon-o-check-circle class="mx-auto h-12 w-12 text-green-400" />
            <h3 class="mt-2 text-sm font-medium text-gray-900">All events are categorised</h3>
            <p class="mt-1 text-sm text-gray-500">Great! All calendar events have been properly categorised.</p>
        </div>
    @endif

</x-admin.page>

</x-admin.shell>
@endsection
