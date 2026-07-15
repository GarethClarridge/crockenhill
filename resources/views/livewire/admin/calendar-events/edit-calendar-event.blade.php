<x-admin.form-shell
    title="Edit calendar event"
    save-action="save"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.calendar-events.index') }}" variant="outline" inline>
            Cancel
        </x-button>
        <x-form-button variant="primary" wire:click="save" icon="check">
            Save event
        </x-form-button>
    </x-slot:actions>

    <x-card heading="Event details">
        <div class="space-y-4">
            <x-input label="Title" wire:model="title" required autofocus />

            <x-textarea label="Description" wire:model="description" rows="4" />

            <x-input label="Speaker" wire:model="speaker"
                placeholder="Guest speaker or leader name" />

            <x-input label="Location" wire:model="location"
                placeholder="e.g., Church Hall, Online" />
        </div>
    </x-card>

    <x-card heading="Schedule">
        <div class="space-y-4">
            <x-input type="datetime-local" label="Start Date & Time"
                wire:model="startDatetime" required />

            <x-input type="datetime-local" label="End Date & Time"
                wire:model="endDatetime" required />
        </div>
    </x-card>

    <x-slot:sidebar>
        <x-card heading="Categorisation">
            <div class="space-y-4">
                <x-select label="Meeting" wire:model="meetingSlug"
                    :options="$meetings->map(fn($name, $slug) => ['id' => $slug, 'name' => $name])->values()->toArray()"
                    placeholder="Select a meeting"
                    hint="Associate this event with a meeting type" />

                <x-select label="Status" wire:model="status"
                    :options="collect(\App\Enums\CalendarEventStatus::cases())->map(fn($s) => ['id' => $s->value, 'name' => $s->label()])->toArray()"
                    required />
            </div>
        </x-card>

        <x-card heading="Info">
            <div class="space-y-2">
                @if($calendarEvent->google_event_id)
                    <p class="text-sm"><span class="font-semibold">Google ID:</span> {{ Str::limit($calendarEvent->google_event_id, 20) }}</p>
                @endif
                <p class="text-sm"><span class="font-semibold">Auto-categorised:</span> {{ $calendarEvent->is_categorized_automatically ? 'Yes' : 'No' }}</p>
            </div>
        </x-card>
    </x-slot:sidebar>
</x-admin.form-shell>
