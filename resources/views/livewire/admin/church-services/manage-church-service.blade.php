<x-admin.form-shell
    title="Create service"
    description="Create a service manually when email or OpenLP is not available"
    save-action="save"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
            Back to services
        </x-button>
        <x-form-button variant="primary" wire:click="save" icon="check">
            Create service
        </x-form-button>
    </x-slot:actions>

    <x-card heading="Service details">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-input
                label="Date"
                type="date"
                wire:model.live="form.date"
                required />

            <x-select
                label="Service"
                wire:model.live="form.service"
                :options="$serviceOptions"
                placeholder="Choose a service"
                required />
        </div>
    </x-card>

    <x-card heading="Service items">
        <x-admin.church-services.planned-items-editor
            :items="$items"
            :section-type-options="$sectionTypeOptions"
            :song-suggestions="$songSuggestions"
            :linked-song-titles="$linkedSongTitles" />
    </x-card>

    <x-slot:sidebar>
        <x-card heading="Guidance">
            <div class="space-y-3 text-sm text-gray-600">
                <p>Use the service type dropdown to capture the planned structure, not just the spoken title.</p>
                <p>Songs can be saved as free text, but linking them to the catalogue improves later matching and reporting.</p>
                <p>Manual saves become the canonical reviewed service list and clear any existing review flag.</p>
            </div>
        </x-card>

    </x-slot:sidebar>
</x-admin.form-shell>
