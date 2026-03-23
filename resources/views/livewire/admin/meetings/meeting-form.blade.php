<div
    x-data="{
        isRecurring: $wire.entangle('form.isRecurring').live,
        frequency: $wire.entangle('form.frequency').live,
        init() {
            this.$watch('isRecurring', (value) => {
                if (!value) {
                    this.frequency = null;
                }
            });
        },
    }"
>
    <div class="flex justify-between items-center mb-6">
        <h1 class="font-display text-3xl">{{ $title }}</h1>
        <div class="flex gap-2">
            <x-button link="{{ route('admin.meetings.index') }}" variant="outline" inline>
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
            <x-card heading="Meeting Details">
                <div class="space-y-4">
                    <x-select label="Page" wire:model="form.pageId" :options="$pages" placeholder="Select a page"
                        hint="Link this meeting to a page for content" />

                    <x-input label="Slug" wire:model="form.slug" required
                        hint="URL-friendly identifier" />

                    <x-select label="Type" wire:model="form.type" :options="$types" required />

                    <x-input label="Who" wire:model="form.who" required
                        hint="Target audience (e.g., 'All ages', 'Adults', etc.)" />

                    <x-input label="Day" wire:model="form.day" required
                        hint="Day of the week or specific date description" />
                </div>
            </x-card>

            <x-card heading="Schedule">
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <x-input type="time" label="Start Time" wire:model="form.startTime" />
                        <x-input type="time" label="End Time" wire:model="form.endTime" />
                    </div>

                    <x-input label="Location" wire:model="form.location"
                        placeholder="e.g., Church Hall, Online" />

                    <x-toggle label="Recurring Meeting" wire:model.live="form.isRecurring" />

                    @if($form->isRecurring)
                        <x-select
                            label="Frequency"
                            wire:model="form.frequency"
                            :options="$frequencies"
                            required />
                    @endif

                    <x-input type="date" label="Meeting Date" wire:model="form.meetingDate"
                        :hint="$form->isRecurring ? 'Date of first occurrence' : 'Date of meeting'" />
                </div>
            </x-card>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-card heading="Contact">
                <div class="space-y-4">
                    <x-input label="Leader's Phone" wire:model="form.leadersPhone"
                        placeholder="+44 1234 567890" />

                    <x-input label="Leader's Email" wire:model="form.leadersEmail"
                        type="email"
                        placeholder="leader@example.com" />
                </div>
            </x-card>

            <x-card heading="Options">
                <div class="space-y-4">
                    <x-toggle label="Show Pictures" wire:model="form.pictures"
                        hint="Display photo gallery for this meeting" />
                </div>
            </x-card>
        </div>
    </div>
</div>
