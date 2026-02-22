<div>
    <div class="rounded-lg bg-white p-6 shadow-md">
        <h2 class="mb-6 text-2xl font-bold text-gray-900">Processing Status</h2>

        @if($processingId)
            <div class="mb-4 rounded-md bg-gray-50 p-3">
                <p class="text-sm text-gray-600">Processing ID: <code class="rounded bg-white px-2 py-1 text-xs">{{ $processingId }}</code></p>
            </div>
        @endif

        <div class="mb-6">
            <div class="mb-2 flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700">{{ $currentStep ?: 'Initializing...' }}</span>
                <span class="text-sm text-gray-500">{{ $progressPercentage }}%</span>
            </div>

            <div class="h-3 w-full rounded-full bg-gray-200">
                <div
                    class="h-3 rounded-full transition-all duration-500 ease-out {{ $status === 'failed' ? 'bg-red-500' : ($status === 'cancelled' ? 'bg-gray-400' : ($status === 'completed' ? 'bg-green-500' : 'bg-blue-500')) }}"
                    style="width: {{ $progressPercentage }}%"
                ></div>
            </div>
        </div>

        @if($successMessage)
            <div class="mb-4 rounded-md border border-green-200 bg-green-50 p-4">
                <div class="flex">
                    <svg class="mt-0.5 mr-3 h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-green-800">Success!</p>
                        <p class="text-sm text-green-700">{{ $successMessage }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if($errorMessage)
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-4">
                <div class="flex">
                    <svg class="mt-0.5 mr-3 h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-red-800">Processing Error</p>
                        <p class="text-sm text-red-700">{{ $errorMessage }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if($cancelledMessage)
            <div class="mb-4 rounded-md border border-gray-200 bg-gray-50 p-4">
                <div class="flex">
                    <svg class="mt-0.5 mr-3 h-5 w-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v4a1 1 0 102 0V7zm-1 8a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" clip-rule="evenodd" />
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-gray-800">Processing Cancelled</p>
                        <p class="text-sm text-gray-700">{{ $cancelledMessage }}</p>
                    </div>
                </div>
            </div>
        @endif

        <div class="flex items-center justify-between">
            @if($status === 'processing')
                <button
                    wire:click="requestCancelProcessing"
                    class="rounded-md bg-red-600 px-4 py-2 text-white transition-colors hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                >
                    Cancel Processing
                </button>
            @else
                <button
                    wire:click="requestRetryUpload"
                    class="rounded-md bg-blue-600 px-4 py-2 text-white transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                >
                    Upload Another File
                </button>
            @endif

            @if($status === 'completed')
                <a href="/christ/sermons" class="rounded-md bg-green-600 px-4 py-2 text-white transition-colors hover:bg-green-700">
                    View All Sermons
                </a>
            @endif
        </div>
    </div>

    @if($processingId)
        <div class="mt-6">
            <livewire:processing-logs-viewer
                :processing-id="$processingId"
                :auto-refresh="$status === 'processing'"
                :expanded="false"
                :log-limit="20"
            />
        </div>
    @endif
</div>
