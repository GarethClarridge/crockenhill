<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="font-display text-3xl">Edit Calendar Event</h1>
        <div class="flex gap-2">
            <x-button link="{{ route('admin.calendar-events.index') }}" variant="outline" inline>
                Cancel
            </x-button>
            <x-form-button variant="primary" wire:click="save" icon="check">
                Save
            </x-form-button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-6">
            <x-card heading="Event Details">
                <div class="space-y-4">
                    <x-input label="Title" wire:model="title" required />

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
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-card heading="Categorization">
                <div class="space-y-4">
                    <x-select label="Meeting" wire:model="meetingSlug"
                        :options="$meetings->map(fn($name, $slug) => ['id' => $slug, 'name' => $name])->values()->toArray()"
                        placeholder="Select a meeting"
                        hint="Associate this event with a meeting type" />
                </div>
            </x-card>

            <x-card heading="Info">
                <div class="space-y-2">
                    <p class="text-sm"><span class="font-semibold">Status:</span> {{ ucfirst($calendarEvent->status) }}</p>
                    @if($calendarEvent->google_event_id)
                        <p class="text-sm"><span class="font-semibold">Google ID:</span> {{ Str::limit($calendarEvent->google_event_id, 20) }}</p>
                    @endif
                    <p class="text-sm"><span class="font-semibold">Auto-categorized:</span> {{ $calendarEvent->is_categorized_automatically ? 'Yes' : 'No' }}</p>
                </div>
            </x-card>
        </div>
    </div>
</div>
