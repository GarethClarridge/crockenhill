<div>
    {{-- Existing media --}}
    @if($existingMedia->isNotEmpty())
        <div class="flex flex-wrap gap-4 mb-4" role="list" aria-label="Uploaded images">
            @foreach($existingMedia as $media)
                <div class="relative group" role="listitem" wire:loading.class="opacity-50 pointer-events-none" wire:target="remove({{ $media->id }})">
                    <img src="{{ $media->getUrl('thumbnail') }}"
                         alt="Uploaded image: {{ $media->name }}"
                         class="w-24 h-24 object-cover rounded-lg"
                         loading="lazy" />
                    <button type="button"
                            wire:click="remove({{ $media->id }})"
                            wire:target="remove({{ $media->id }})"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-100"
                            wire:confirm="Remove this image?"
                            aria-label="Remove image: {{ $media->name }}"
                            class="absolute -top-2 -right-2 w-5 h-5 flex items-center justify-center rounded-full bg-red-500 text-white opacity-0 group-hover:opacity-100 transition-opacity disabled:opacity-50 disabled:pointer-events-none">
                        <x-heroicon-o-x-mark wire:loading.remove wire:target="remove({{ $media->id }})" class="w-3 h-3" aria-hidden="true" />
                        <svg wire:loading wire:target="remove({{ $media->id }})" class="animate-spin h-3 w-3 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Upload area --}}
    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-cbc-teal transition-colors">
        <label for="file-upload" class="sr-only">Choose image file</label>
        <input type="file"
               id="file-upload"
               wire:model="file"
               accept="{{ $accept }}"
               @if($multiple) multiple @endif
               aria-describedby="file-help @error('file') file-error @enderror"
               @error('file') aria-invalid="true" @enderror
               class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-green-50 file:text-green-700 hover:file:bg-green-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded-md" />
        <p id="file-help" class="text-sm text-gray-500 mt-2">
            Accepted formats: JPG, PNG, WebP. Max size: {{ round($maxSize / 1024, 1) }}MB
        </p>

        @if($file)
            <div class="mt-4">
                <img src="{{ $file->temporaryUrl() }}" alt="Preview of selected image" class="w-32 h-32 object-cover rounded-lg mx-auto" loading="lazy" />
                <x-form-button variant="primary" size="sm" wire:click="upload" class="mt-2">
                    Upload
                </x-form-button>
            </div>
        @endif
    </div>

    @error('file')
        <p id="file-error" class="text-red-600 text-sm mt-2" role="alert">{{ $message }}</p>
    @enderror
</div>
