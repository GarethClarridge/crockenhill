<div>
    <x-mary-header title="Calendar Events" subtitle="Manage and categorize calendar events">
        <x-slot:actions>
            <x-mary-button label="Sync Calendar" icon="o-arrow-path"
                link="{{ route('admin.calendar.sync') }}" class="btn-secondary" />
        </x-slot:actions>
    </x-mary-header>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <x-mary-input placeholder="Search events..." wire:model.live.debounce="search"
            icon="o-magnifying-glass" clearable class="w-64" />

        <x-mary-select placeholder="All Meetings" wire:model.live="meetingFilter"
            :options="$meetings->map(fn($name, $slug) => ['id' => $slug, 'name' => $name])"
            class="w-48" />

        <x-mary-toggle label="Uncategorized Only" wire:model.live="uncategorizedOnly" />
        <x-mary-toggle label="Upcoming Only" wire:model.live="upcomingOnly" />
    </div>

    {{-- Table --}}
    <x-mary-card>
        <x-mary-table :headers="$headers" :rows="$events" striped>
            @scope('cell_title', $event)
                <div>
                    <p class="font-medium">{{ $event->title }}</p>
                    @if($event->speaker)
                        <p class="text-sm text-base-content/60">Speaker: {{ $event->speaker }}</p>
                    @endif
                </div>
            @endscope

            @scope('cell_datetime', $event)
                <div>
                    <p class="font-medium">{{ $event->start_datetime->format('j M Y') }}</p>
                    <p class="text-sm text-base-content/60">
                        {{ $event->start_datetime->format('H:i') }} - {{ $event->end_datetime->format('H:i') }}
                    </p>
                </div>
            @endscope

            @scope('cell_meeting', $event)
                @if($event->meeting_slug)
                    <div>
                        <x-mary-badge :value="$event->meeting->page?->heading ?? $event->meeting_slug"
                            class="badge-success" />
                        @if($event->is_categorized_automatically)
                            <span class="text-xs text-base-content/60">(auto)</span>
                        @endif
                    </div>
                @else
                    <div class="flex gap-2 items-center">
                        <x-mary-badge value="Uncategorized" class="badge-warning" />
                        <x-mary-select
                            wire:change="categorize({{ $event->id }}, $event.target.value)"
                            :options="$meetings->map(fn($name, $slug) => ['id' => $slug, 'name' => $name])"
                            placeholder="Categorize..."
                            class="select-xs w-40"
                        />
                    </div>
                @endif
            @endscope

            @scope('cell_location', $event)
                <span class="text-sm">{{ $event->location ?? '-' }}</span>
            @endscope

            @scope('cell_status', $event)
                <x-mary-badge :value="ucfirst($event->status)"
                    class="{{ $event->status === 'confirmed' ? 'badge-success' : 'badge-ghost' }}" />
            @endscope

            @scope('actions', $event)
                <div class="flex gap-1" role="group" aria-label="Actions for {{ $event->title }}">
                    <x-mary-button icon="o-pencil" link="{{ route('admin.calendar-events.edit', $event) }}" class="btn-ghost btn-xs" aria-label="Edit event" />
                </div>
            @endscope
        </x-mary-table>

        <div class="mt-4">
            {{ $events->links() }}
        </div>
    </x-mary-card>
</div>
