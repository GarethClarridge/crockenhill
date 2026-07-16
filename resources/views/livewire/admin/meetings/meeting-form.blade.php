<x-admin.form-shell :title="$title" save-action="save">
    <x-slot:actions>
        <x-button link="{{ route('admin.meetings.index') }}" variant="outline" inline>
            Cancel
        </x-button>
        <x-form-button variant="primary" wire:click="save" icon="check" loading-label="Saving meeting...">
            Save meeting
        </x-form-button>
    </x-slot:actions>

    <x-card heading="Meeting details">
        <div class="space-y-4">
            <x-select label="Page" wire:model="form.pageId" :options="$pages" placeholder="Select a page"
                hint="Link this meeting to a page for content" />

            <div class="flex items-start gap-2">
                <div class="flex-1">
                    <x-input label="Slug" wire:model="form.slug" required
                        hint="URL-friendly identifier" />
                </div>
                <x-clipboard-button js-content="$wire.form.slug" hideLabel label="Copy Slug" title="Copy slug to clipboard" class="mt-7" />
            </div>

            <x-select label="Type" wire:model="form.type" :options="$types" required />

            <x-input label="Who" wire:model="form.who" required
                hint="Target audience (e.g., 'All ages', 'Adults', etc.)" />

            <x-input label="Day" wire:model="form.day"
                hint="Day of the week or specific date description. Leave blank for events with no fixed schedule." />
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
