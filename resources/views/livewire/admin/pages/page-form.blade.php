<div>
    <x-mary-header :title="$title">
        <x-slot:actions>
            <x-mary-button label="Cancel" link="{{ route('admin.pages.index') }}" />
            <x-mary-button label="Save" wire:click="save" class="btn-primary" spinner />
        </x-slot:actions>
    </x-mary-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-6">
            <x-mary-card title="Page Details">
                <div class="space-y-4">
                    <x-mary-input label="Heading" wire:model.live.debounce="heading" required />

                    <x-mary-input label="Slug" wire:model="slug" required
                        hint="URL-friendly identifier (auto-generated from heading)" />

                    <x-mary-textarea label="Description" wire:model="description" rows="3" required
                        hint="{{ 500 - strlen($description) }} characters remaining" />
                </div>
            </x-mary-card>

            <x-mary-card title="Content">
                {{-- Simple textarea for markdown - could enhance with editor later --}}
                <x-mary-textarea
                    label="Content (Markdown)"
                    wire:model="markdown"
                    rows="20"
                    class="font-mono text-sm"
                    hint="Supports Markdown formatting" />
            </x-mary-card>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-mary-card title="Settings">
                <div class="space-y-4">
                    <x-mary-select label="Area" wire:model="area" :options="$areas" required />

                    <x-mary-toggle label="Show in Navigation" wire:model="navigation" />
                </div>
            </x-mary-card>

            @if(isset($page) && $page->exists)
                <x-mary-card title="Heading Image">
                    <livewire:admin.components.media-upload-field
                        :model="$page"
                        collection="headings"
                        accept="image/jpeg,image/png,image/webp" />
                </x-mary-card>
            @else
                <x-mary-card title="Heading Image">
                    <p class="text-sm text-base-content/60">Save the page first to upload an image.</p>
                </x-mary-card>
            @endif
        </div>
    </div>
</div>
