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
                    class="h-3 rounded-full transition-all duration-500 ease-out {{ match ($status) {
                        \App\Enums\UploadState::ManualReview => 'bg-amber-400',
                        \App\Enums\UploadState::Failed => 'bg-red-500',
                        \App\Enums\UploadState::Cancelled => 'bg-gray-400',
                        \App\Enums\UploadState::Completed => 'bg-green-500',
                        default => 'bg-blue-500',
                    } }}"
                    style="width: {{ $progressPercentage }}%"
                    role="progressbar"
                    aria-label="Processing progress"
                    aria-valuenow="{{ $progressPercentage }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                ></div>
            </div>
        </div>

        @if($this->statusMessage && $status === \App\Enums\UploadState::Completed)
            <div class="mb-4 rounded-md border border-green-200 bg-green-50 p-4">
                <div class="flex">
                    <x-heroicon-o-check-circle class="mt-0.5 mr-3 h-5 w-5 text-green-400" />
                    <div>
                        <p class="text-sm font-medium text-green-800">Success!</p>
                        <p class="text-sm text-green-700">{{ $this->statusMessage }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if($status === \App\Enums\UploadState::ManualReview)
            <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-4">
                <div class="flex">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 mr-3 h-5 w-5 text-amber-500" />
                    <div>
                        <p class="text-sm font-medium text-amber-800">Manual Review Required</p>
                        <p class="mt-1 text-sm text-amber-700">{{ $this->statusMessage }}</p>
                        @if($this->statusUrl)
                            <div class="mt-3">
                                <x-button :link="$this->statusUrl" variant="warning" size="sm" icon="chevron-right" iconPosition="trailing">
                                    Choose segment
                                </x-button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if($status === \App\Enums\UploadState::Failed)
            <div class="mb-4 rounded-md border border-red-200 bg-red-50 p-4">
                <div class="flex">
                    <x-heroicon-o-x-circle class="mt-0.5 mr-3 h-5 w-5 text-red-400" />
                    <div>
                        <p class="text-sm font-medium text-red-800">Processing Error</p>
                        <p class="text-sm text-red-700">{{ $this->statusMessage }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if($status === \App\Enums\UploadState::Cancelled)
            <div class="mb-4 rounded-md border border-gray-200 bg-gray-50 p-4">
                <div class="flex">
                    <x-heroicon-o-information-circle class="mt-0.5 mr-3 h-5 w-5 text-gray-500" />
                    <div>
                        <p class="text-sm font-medium text-gray-800">Processing Cancelled</p>
                        <p class="text-sm text-gray-700">{{ $this->statusMessage }}</p>
                    </div>
                </div>
            </div>
        @endif

        <div class="flex items-center justify-between">
            @if($status === \App\Enums\UploadState::Processing)
                <x-form-button
                    variant="danger"
                    wire:click="cancelProcessing"
                    wire:target="cancelProcessing"
                    loading-label="Cancelling..."
                >
                    Cancel Processing
                </x-form-button>
            @else
                <x-form-button
                    variant="primary"
                    wire:click="retryUpload"
                    wire:target="retryUpload"
                    loading-label="Preparing..."
                >
                    Upload Another File
                </x-form-button>
            @endif

            @if($status === \App\Enums\UploadState::Completed)
                <div class="flex items-center gap-2">
                    @if($this->statusUrl)
                        <x-button :link="$this->statusUrl" variant="primary" icon="calendar">
                            Open service
                        </x-button>
                    @endif
                    <x-button link="/christ/sermons" variant="success" icon="magnifying-glass">
                        Browse Sermons
                    </x-button>
                </div>
            @endif
        </div>
    </div>

</div>
