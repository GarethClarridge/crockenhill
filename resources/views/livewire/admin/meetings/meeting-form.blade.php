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
    <x-admin.form-shell :title="$title">
        <x-slot:actions>
            <x-button link="{{ route('admin.meetings.index') }}" variant="outline" inline>
                Cancel
            </x-button>
            <x-form-button variant="primary" wire:click="save" icon="check" data-form-action>
                Save
            </x-form-button>
        </x-slot:actions>

        <x-card heading="Meeting details">
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

        <x-slot:sidebar>
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
        </x-slot:sidebar>
    </x-admin.form-shell>
</div>
