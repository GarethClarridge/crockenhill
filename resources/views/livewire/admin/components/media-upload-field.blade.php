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
                            class="absolute -top-2 -right-2 btn btn-circle btn-error btn-xs opacity-0 group-hover:opacity-100 transition-opacity">
                        <x-mary-icon name="o-x-mark" class="w-3 h-3" aria-hidden="true" />
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Upload area --}}
    <div class="border-2 border-dashed border-base-300 rounded-lg p-6 text-center hover:border-primary transition-colors">
        <label for="file-upload" class="sr-only">Choose image file</label>
        <input type="file"
               id="file-upload"
               wire:model="file"
               accept="{{ $accept }}"
               @if($multiple) multiple @endif
               aria-describedby="file-help"
               class="file-input file-input-bordered w-full max-w-xs" />
        <p id="file-help" class="text-sm text-base-content/60 mt-2">
            Accepted formats: JPG, PNG, WebP. Max size: {{ round($maxSize / 1024, 1) }}MB
        </p>

        @if($file)
            <div class="mt-4">
                <img src="{{ $file->temporaryUrl() }}" alt="Preview of selected image" class="w-32 h-32 object-cover rounded-lg mx-auto" />
                <x-mary-button label="Upload" wire:click="upload" class="btn-primary btn-sm mt-2" aria-label="Upload selected image" />
            </div>
        @endif
    </div>

    @error('file')
        <p class="text-error text-sm mt-2" role="alert">{{ $message }}</p>
    @enderror
</div>
