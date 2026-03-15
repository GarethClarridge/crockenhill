<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="font-display text-3xl">Upload Service</h1>
        <div class="flex gap-2">
            <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
                Back to Services
            </x-button>
            <x-button link="{{ route('admin.services.create') }}" variant="outline" icon="plus" inline>
                Create Service
            </x-button>
            <x-button link="{{ route('admin.services.songs.index') }}" variant="outline" icon="musical-note" inline>
                Song Catalog
            </x-button>
            <x-button link="{{ route('admin.services.submit-email') }}" variant="outline" icon="envelope" inline>
                Submit Email Text
            </x-button>
            <x-form-button variant="primary" wire:click="save" icon="arrow-up-tray">
                Import
            </x-form-button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-card heading="OpenLP Archive">
                <div class="space-y-4">
                    <p class="text-sm text-gray-600">Upload a `.osz` archive exported from OpenLP. The service date and slot are inferred from the filename.</p>

                    <div>
                        <label for="service-upload" class="block text-sm font-medium text-gray-700 mb-1">
                            File
                            <span class="text-red-500">*</span>
                        </label>
                        <input
                            id="service-upload"
                            type="file"
                            wire:model="file"
                            accept=".osz,.zip,application/zip"
                            class="block w-full rounded-md border-gray-300 shadow-sm sm:text-sm focus:border-green-500 focus:ring-green-500" />

                        <p class="mt-1 text-sm text-gray-500">
                            Max size: {{ round(((int) config('service-tracking.upload.max_size_kb', 614400)) / 1024, 1) }} MB
                        </p>

                        @error('file')
                            <p class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    @if($file)
                        <div class="rounded-md border border-green-200 bg-green-50 px-4 py-3">
                            <p class="text-sm text-green-800">
                                Selected: <span class="font-medium">{{ $file->getClientOriginalName() }}</span>
                            </p>
                        </div>
                    @endif

                    <div class="text-sm text-gray-500" wire:loading wire:target="file,save">
                        Processing upload...
                    </div>
                </div>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card heading="Import Notes">
                <div class="space-y-3 text-sm text-gray-600">
                    <p>Accepted format: OpenLP `.osz` zip archive.</p>
                    <p>Date and service are inferred from the filename pattern, for example `2026-02-22 AM.osz`.</p>
                    <p>Low-confidence imports are saved with a review flag so they can be checked later.</p>
                </div>
            </x-card>

            <x-card heading="Recent Imports">
                <div class="space-y-3">
                    @forelse($recentServices as $service)
                        <a href="{{ route('admin.services.show', $service) }}" wire:navigate class="block rounded-md border border-gray-200 px-3 py-2 hover:bg-gray-50 no-underline">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-medium text-gray-900">{{ $service->date->format('j M Y') }} ({{ $service->service->label() }})</p>
                                <span class="text-xs text-gray-500">{{ $service->items_count }} {{ \Illuminate\Support\Str::plural('item', $service->items_count) }}</span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">{{ $service->original_filename }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">No imports yet.</p>
                    @endforelse
                </div>
            </x-card>
        </div>
    </div>
</div>
