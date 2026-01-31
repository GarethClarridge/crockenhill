<div>
    <x-mary-header title="Edit Calendar Event">
        <x-slot:actions>
            <x-mary-button label="Cancel" link="{{ route('admin.calendar-events.index') }}" />
            <x-mary-button label="Save" wire:click="save" class="btn-primary" spinner />
        </x-slot:actions>
    </x-mary-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-6">
            <x-mary-card title="Event Details">
                <div class="space-y-4">
                    <x-mary-input label="Title" wire:model="title" required />

                    <x-mary-textarea label="Description" wire:model="description" rows="4" />

                    <x-mary-input label="Speaker" wire:model="speaker"
                        placeholder="Guest speaker or leader name" />

                    <x-mary-input label="Location" wire:model="location"
                        placeholder="e.g., Church Hall, Online" />
                </div>
            </x-mary-card>

            <x-mary-card title="Schedule">
                <div class="space-y-4">
                    <x-mary-input type="datetime-local" label="Start Date & Time"
                        wire:model="startDatetime" required />

                    <x-mary-input type="datetime-local" label="End Date & Time"
                        wire:model="endDatetime" required />
                </div>
            </x-mary-card>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-mary-card title="Categorization">
                <div class="space-y-4">
                    <x-mary-select label="Meeting" wire:model="meetingSlug"
                        :options="$meetings->map(fn($name, $slug) => ['id' => $slug, 'name' => $name])"
                        placeholder="Select a meeting"
                        hint="Associate this event with a meeting type" />
                </div>
            </x-mary-card>

            <x-mary-card title="Info">
                <div class="space-y-2">
                    <p class="text-sm"><span class="font-semibold">Status:</span> {{ ucfirst($calendarEvent->status) }}</p>
                    @if($calendarEvent->google_event_id)
                        <p class="text-sm"><span class="font-semibold">Google ID:</span> {{ Str::limit($calendarEvent->google_event_id, 20) }}</p>
                    @endif
                    <p class="text-sm"><span class="font-semibold">Auto-categorized:</span> {{ $calendarEvent->is_categorized_automatically ? 'Yes' : 'No' }}</p>
                </div>
            </x-mary-card>
        </div>
    </div>
</div>
