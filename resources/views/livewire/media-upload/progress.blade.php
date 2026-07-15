<div data-upload-progress hidden>
        <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-blue-900">
                    Uploading <span data-upload-file-name>{{ $currentFileName ?? 'file' }}</span>...
                </h3>
                <x-form-button
                    variant="ghost"
                    size="xs"
                    class="text-red-600 hover:bg-red-50"
                    x-on:click="$dispatch('media-upload-cancel')"
                    dusk="cancel-upload"
                >
                    Cancel
                </x-form-button>
            </div>

            <div class="mb-2">
                <div class="h-3 w-full rounded-full bg-blue-200">
                    <div
                        class="h-3 rounded-full bg-blue-600 transition-all duration-300 ease-out"
                        data-upload-progress-bar
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
                    <span data-upload-progress-value>{{ $uploadProgress }}</span>%
                </span>
                <span class="text-xs text-blue-600">
                    Processing will start automatically when upload completes
                </span>
            </div>
        </div>
</div>
