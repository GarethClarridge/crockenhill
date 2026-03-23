<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="font-display text-3xl">{{ $title }}</h1>
        <div class="flex gap-2">
            <x-button link="{{ route('admin.pages.index') }}" variant="outline" inline>
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
            <x-card heading="Page Details">
                <div class="space-y-4">
                    <x-input label="Heading" wire:model.live.debounce="heading" required />

                    <x-input label="Slug" wire:model="slug" required
                        hint="URL-friendly identifier (auto-generated from heading)" />

                    <x-textarea label="Description" wire:model="description" rows="3" required
                        maxlength="500" hint="Brief summary for listings and SEO" />
                </div>
            </x-card>

            <x-card heading="Content">
                {{-- Simple textarea for markdown - could enhance with editor later --}}
                <x-textarea
                    label="Content (Markdown)"
                    wire:model="markdown"
                    rows="20"
                    class="font-mono text-sm"
                    hint="Supports Markdown formatting" />
            </x-card>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-card heading="Settings">
                <div class="space-y-4">
                    <x-select label="Area" wire:model="area" :options="$areas" required />

                    <x-toggle
                        label="Admin only"
                        wire:model="admin"
                        hint="Restrict this page so only administrators can view it."
                    />

                    <x-toggle label="Show in Navigation" wire:model="navigation" />
                </div>
            </x-card>

            @if(isset($page) && $page->exists)
                <x-card heading="Heading Image">
                    <livewire:admin.components.media-upload-field
                        :model="$page"
                        collection="headings"
                        accept="image/jpeg,image/png,image/webp" />
                </x-card>
            @else
                <x-card heading="Heading Image">
                    <p class="text-sm text-gray-500">Save the page first to upload an image.</p>
                </x-card>
            @endif
        </div>
    </div>
</div>
