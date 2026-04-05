<div
    x-data="{
        heading: $wire.entangle('form.heading').live,
        slug: $wire.entangle('form.slug').live,
        lastGeneratedSlug: '',
        slugify(value) {
            return value
                .toLowerCase()
                .trim()
                .replace(/[^\w\s-]/g, '')
                .replace(/[\s_-]+/g, '-')
                .replace(/^-+|-+$/g, '');
        },
        init() {
            this.lastGeneratedSlug = this.slugify(this.heading);

            this.$watch('heading', (value) => {
                const generatedSlug = this.slugify(value);

                if (this.slug === '' || this.slug === this.lastGeneratedSlug) {
                    this.slug = generatedSlug;
                }

                this.lastGeneratedSlug = generatedSlug;
            });
        },
    }"
>
    <x-admin.form-shell :title="$title">
        <x-slot:actions>
            <x-button link="{{ route('admin.pages.index') }}" variant="outline" inline>
                Cancel
            </x-button>
            <x-form-button variant="primary" wire:click="save" icon="check">
                Save
            </x-form-button>
        </x-slot:actions>

        <x-card heading="Page details">
            <div class="space-y-4">
                <x-input label="Heading" wire:model.live.debounce="form.heading" required maxlength="255" />

                <x-input label="Slug" wire:model="form.slug" required maxlength="255"
                    hint="URL-friendly identifier (auto-generated from heading)" />

                <x-textarea label="Description" wire:model="form.description" rows="3" required
                    maxlength="500" hint="Brief summary for listings and SEO" />
            </div>
        </x-card>

        <x-card heading="Content">
            <x-textarea
                label="Content (Markdown)"
                wire:model="form.markdown"
                rows="20"
                class="font-mono text-sm"
                hint="Supports Markdown formatting" />
        </x-card>

        <x-slot:sidebar>
            <x-card heading="Settings">
                <div class="space-y-4">
                    <x-select label="Area" wire:model="form.area" :options="$areas" required />

                    <x-toggle
                        label="Admin only"
                        wire:model="form.admin"
                        hint="Restrict this page so only administrators can view it."
                    />

                    <x-toggle label="Show in Navigation" wire:model="form.navigation" />
                </div>
            </x-card>

            @if(isset($page) && $page->exists)
                <x-card heading="Heading image">
                    <livewire:admin.components.media-upload-field
                        :model="$page"
                        collection="headings"
                        accept="image/jpeg,image/png,image/webp" />
                </x-card>
            @else
                <x-card heading="Heading image">
                    <p class="text-sm text-gray-500">Save the page first to upload an image.</p>
                </x-card>
            @endif
        </x-slot:sidebar>
    </x-admin.form-shell>
</div>
