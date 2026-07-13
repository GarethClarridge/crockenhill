<div>
    @if($isUploading && $status === \App\Enums\UploadState::Uploading)
        <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-blue-900">
                    Uploading {{ $currentFileName ?? 'file' }}...
                </h3>
                <x-form-button
                    variant="ghost"
                    size="xs"
                    class="text-red-600 hover:bg-red-50"
                    wire:click="cancelUpload"
                    wire:target="cancelUpload"
                >
                    Cancel
                </x-form-button>
            </div>

            <div class="mb-2">
                <div class="h-3 w-full rounded-full bg-blue-200">
                    <div
                        class="h-3 rounded-full bg-blue-600 transition-all duration-300 ease-out"
                        style="width: {{ $uploadProgress }}%"
                        role="progressbar"
                        aria-label="Upload progress"
                        aria-valuenow="{{ $uploadProgress }}"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    ></div>
                </div>
            </div>

            <div class="flex items-center justify-between text-sm">
                <span class="text-blue-700">
                    {{ $uploadProgress }}%
                </span>
                <span class="text-xs text-blue-600">
                    Processing will start automatically when upload completes
                </span>
            </div>
        </div>
    @endif
</div>
