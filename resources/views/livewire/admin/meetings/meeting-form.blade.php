<div>
    <x-mary-header :title="$title">
        <x-slot:actions>
            <x-mary-button label="Cancel" link="{{ route('admin.meetings.index') }}" />
            <x-mary-button label="Save" wire:click="save" class="btn-primary" spinner />
        </x-slot:actions>
    </x-mary-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-6">
            <x-mary-card title="Meeting Details">
                <div class="space-y-4">
                    <x-mary-select label="Page" wire:model="pageId" :options="$pages" placeholder="Select a page"
                        hint="Link this meeting to a page for content" />

                    <x-mary-input label="Slug" wire:model="slug" required
                        hint="URL-friendly identifier" />

                    <x-mary-select label="Type" wire:model="type" :options="$types" required />

                    <x-mary-input label="Who" wire:model="who" required
                        hint="Target audience (e.g., 'All ages', 'Adults', etc.)" />

                    <x-mary-input label="Day" wire:model="day" required
                        hint="Day of the week or specific date description" />
                </div>
            </x-mary-card>

            <x-mary-card title="Schedule">
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <x-mary-input type="time" label="Start Time" wire:model="startTime" />
                        <x-mary-input type="time" label="End Time" wire:model="endTime" />
                    </div>

                    <x-mary-input label="Location" wire:model="location"
                        placeholder="e.g., Church Hall, Online" />

                    <x-mary-toggle label="Recurring Meeting" wire:model.live="isRecurring" />

                    @if($isRecurring)
                        <x-mary-select
                            label="Frequency"
                            wire:model="frequency"
                            :options="$frequencies"
                            required />
                    @endif

                    <x-mary-input type="date" label="Meeting Date" wire:model="meetingDate"
                        :hint="$isRecurring ? 'Date of first occurrence' : 'Date of meeting'" />
                </div>
            </x-mary-card>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-mary-card title="Contact">
                <div class="space-y-4">
                    <x-mary-input label="Leader's Phone" wire:model="leadersPhone"
                        placeholder="+44 1234 567890" />

                    <x-mary-input label="Leader's Email" wire:model="leadersEmail"
                        type="email"
                        placeholder="leader@example.com" />
                </div>
            </x-mary-card>

            <x-mary-card title="Options">
                <div class="space-y-4">
                    <x-mary-toggle label="Show Pictures" wire:model="pictures"
                        hint="Display photo gallery for this meeting" />
                </div>
            </x-mary-card>
        </div>
    </div>
</div>
