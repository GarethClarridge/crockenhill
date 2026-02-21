<div>
    @if($isUploading && $status === 'uploading')
        <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-blue-900">
                    Uploading {{ $currentFileName ?? 'file' }}...
                </h3>
                <button
                    x-on:click="cancelUpload()"
                    class="text-sm font-medium text-red-600 transition-colors hover:text-red-800"
                    type="button"
                >
                    Cancel
                </button>
            </div>

            <div class="mb-2">
                <div class="h-3 w-full rounded-full bg-blue-200">
                    <div
                        class="h-3 rounded-full bg-blue-600 transition-all duration-300 ease-out"
                        style="width: {{ $uploadProgress }}%"
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
