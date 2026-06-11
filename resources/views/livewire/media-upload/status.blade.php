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
                    class="h-3 rounded-full transition-all duration-500 ease-out {{ $manualReviewMessage ? 'bg-amber-400' : ($status === 'failed' ? 'bg-red-500' : ($status === 'cancelled' ? 'bg-gray-400' : ($status === 'completed' ? 'bg-green-500' : 'bg-blue-500'))) }}"
                    style="width: {{ $progressPercentage }}%"
                    role="progressbar"
                    aria-valuenow="{{ $progressPercentage }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-label="Processing progress"
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

        @if($manualReviewMessage)
            <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-4">
                <div class="flex">
                    <svg class="mt-0.5 mr-3 h-5 w-5 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                    <div>
                        <p class="text-sm font-medium text-amber-800">Manual Review Required</p>
                        <p class="mt-1 text-sm text-amber-700">{{ $manualReviewMessage }}</p>
                        @if($manualReviewUrl)
                            <a href="{{ $manualReviewUrl }}" class="mt-3 inline-flex items-center rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-amber-700">
                                Review Segments
                                <svg class="ml-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </a>
                        @endif
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
                    class="rounded-md bg-cbc-teal px-4 py-2 text-white transition-colors hover:bg-cbc-teal-dark focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2"
                >
                    Upload Another File
                </button>
            @endif

            @if($status === 'completed')
                <div class="flex items-center gap-2">
                    @if($matchedServiceUrl)
                        <a href="{{ $matchedServiceUrl }}" wire:navigate class="rounded-md bg-cbc-teal px-4 py-2 text-white transition-colors hover:bg-cbc-teal-dark">
                            Open service
                        </a>
                    @endif
                    <a href="/christ/sermons" wire:navigate class="rounded-md bg-green-600 px-4 py-2 text-white transition-colors hover:bg-green-700">
                        Browse Sermons
                    </a>
                </div>
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
