<div>
    {{-- Existing media --}}
    @if($existingMedia->isNotEmpty())
        <div class="flex flex-wrap gap-4 mb-4" role="list" aria-label="Uploaded images">
            @foreach($existingMedia as $media)
                <div class="relative group" role="listitem">
                    <img src="{{ $media->getUrl('thumbnail') }}"
                         alt="Uploaded image: {{ $media->name }}"
                         class="w-24 h-24 object-cover rounded-lg" />
                    <button type="button"
                            wire:click="remove({{ $media->id }})"
                            wire:confirm="Remove this image?"
                            aria-label="Remove image: {{ $media->name }}"
                            class="absolute -top-2 -right-2 w-5 h-5 flex items-center justify-center rounded-full bg-red-500 text-white opacity-0 group-hover:opacity-100 transition-opacity">
                        <x-heroicon-o-x-mark class="w-3 h-3" aria-hidden="true" />
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Upload area --}}
    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-green-500 transition-colors">
        <label for="file-upload" class="sr-only">Choose image file</label>
        <input type="file"
               id="file-upload"
               wire:model="file"
               accept="{{ $accept }}"
               @if($multiple) multiple @endif
               aria-describedby="file-help"
               class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-green-50 file:text-green-700 hover:file:bg-green-100" />
        <p id="file-help" class="text-sm text-gray-500 mt-2">
            Accepted formats: JPG, PNG, WebP. Max size: {{ round($maxSize / 1024, 1) }}MB
        </p>

        @if($file)
            <div class="mt-4">
                <img src="{{ $file->temporaryUrl() }}" alt="Preview of selected image" class="w-32 h-32 object-cover rounded-lg mx-auto" />
                <x-form-button variant="primary" size="sm" wire:click="upload" class="mt-2">
                    Upload
                </x-form-button>
            </div>
        @endif
    </div>

    @error('file')
        <p class="text-red-600 text-sm mt-2" role="alert">{{ $message }}</p>
    @enderror
</div>
