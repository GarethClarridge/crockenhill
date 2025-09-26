<div x-data="logsViewer()" x-init="init()" class="bg-white rounded-lg shadow-sm border">
    {{-- Header --}}
    <div class="flex items-center justify-between p-4 border-b">
        <div class="flex items-center space-x-3">
            <h3 class="text-lg font-semibold text-gray-900">Processing Details</h3>
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                {{ $this->statusColor === 'green' ? 'bg-green-100 text-green-800' : '' }}
                {{ $this->statusColor === 'blue' ? 'bg-blue-100 text-blue-800' : '' }}
                {{ $this->statusColor === 'red' ? 'bg-red-100 text-red-800' : '' }}
                {{ $this->statusColor === 'gray' ? 'bg-gray-100 text-gray-800' : '' }}
            ">
                {{ ucfirst($statusData['status'] ?? 'Unknown') }}
            </span>
        </div>

        <div class="flex items-center space-x-2">
            {{-- Auto-refresh toggle --}}
            <label class="flex items-center text-sm text-gray-600">
                <input
                    type="checkbox"
                    wire:model.live="autoRefresh"
                    wire:change="toggleAutoRefresh"
                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                >
                <span class="ml-1">Auto-refresh</span>
            </label>

            {{-- Manual refresh button --}}
            <button
                wire:click="refreshLogs"
                class="p-1 text-gray-500 hover:text-gray-700 rounded"
                title="Refresh logs"
            >
                <svg wire:loading.remove wire:target="refreshLogs" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                <svg wire:loading wire:target="refreshLogs" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </button>

            {{-- Toggle expanded --}}
            <button
                @click="toggleExpanded()"
                class="text-gray-500 hover:text-gray-700 focus:outline-none"
            >
                <span x-show="!expanded" class="text-sm">Show Logs</span>
                <span x-show="expanded" class="text-sm">Hide Logs</span>
                <svg x-show="!expanded" class="inline w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
                <svg x-show="expanded" class="inline w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                </svg>
            </button>
        </div>
    </div>

    <div x-show="expanded" x-transition class="p-4">
        {{-- Performance Summary --}}
        @if($showMetrics && !empty($performanceMetrics))
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-blue-50 p-3 rounded-lg">
                    <div class="text-sm text-blue-600 font-medium">Processing Time</div>
                    <div class="text-lg font-semibold text-blue-900">{{ $this->processingTime }}</div>
                </div>
                <div class="bg-green-50 p-3 rounded-lg">
                    <div class="text-sm text-green-600 font-medium">Memory Peak</div>
                    <div class="text-lg font-semibold text-green-900">{{ $this->memoryPeak }}</div>
                </div>
                <div class="bg-purple-50 p-3 rounded-lg">
                    <div class="text-sm text-purple-600 font-medium">Current Step</div>
                    <div class="text-lg font-semibold text-purple-900">{{ $this->currentStep }}</div>
                </div>
            </div>
        @endif

        {{-- Filters --}}
        @if(!empty($logs))
            <div class="flex flex-wrap gap-4 mb-4 p-3 bg-gray-50 rounded-lg">
                {{-- Log limit --}}
                <div class="flex items-center space-x-2">
                    <label class="text-sm font-medium text-gray-700">Limit:</label>
                    <select
                        wire:model.live="logLimit"
                        wire:change="updateLogLimit"
                        class="text-sm border-gray-300 rounded"
                    >
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>

                {{-- Level filter --}}
                <div class="flex items-center space-x-2">
                    <label class="text-sm font-medium text-gray-700">Level:</label>
                    <select
                        wire:model.live="filterLevel"
                        wire:change="updateFilter"
                        class="text-sm border-gray-300 rounded"
                    >
                        <option value="all">All</option>
                        <option value="error">Errors</option>
                        <option value="warning">Warnings</option>
                        <option value="info">Info</option>
                        <option value="debug">Debug</option>
                    </select>
                </div>

                {{-- Step filter --}}
                @if(!empty($this->availableSteps))
                    <div class="flex items-center space-x-2">
                        <label class="text-sm font-medium text-gray-700">Step:</label>
                        <select
                            wire:model.live="filterStep"
                            wire:change="updateFilter"
                            class="text-sm border-gray-300 rounded"
                        >
                            <option value="all">All Steps</option>
                            @foreach($this->availableSteps as $step)
                                <option value="{{ $step }}">{{ ucfirst(str_replace('_', ' ', $step)) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- Results count --}}
                <div class="flex items-center text-sm text-gray-600 ml-auto">
                    Showing {{ $this->filteredLogs->count() }} of {{ count($logs) }} entries
                </div>
            </div>
        @endif

        {{-- Processing Timeline --}}
        @if(!empty($logs))
            <div class="space-y-3 max-h-96 overflow-y-auto">
                @forelse($this->filteredLogs as $log)
                    <div class="flex items-start space-x-3 p-3 border rounded-lg
                        @if($log['level'] === 'error') bg-red-50 border-red-200
                        @elseif($log['level'] === 'warning') bg-yellow-50 border-yellow-200
                        @elseif($log['level'] === 'info') bg-blue-50 border-blue-200
                        @else bg-gray-50 border-gray-200 @endif
                    ">
                        {{-- Icon --}}
                        <div class="flex-shrink-0 mt-0.5">
                            @if($log['level'] === 'error')
                                <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                            @elseif($log['level'] === 'warning')
                                <svg class="w-5 h-5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                            @elseif($log['level'] === 'info')
                                <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                </svg>
                            @else
                                <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            @endif
                        </div>

                        {{-- Content --}}
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-1">
                                <h4 class="text-sm font-medium text-gray-900">
                                    {{ ucfirst(str_replace('_', ' ', $log['step'])) }}
                                </h4>
                                <span class="text-xs text-gray-500">
                                    {{ \Carbon\Carbon::parse($log['timestamp'])->diffForHumans() }}
                                </span>
                            </div>

                            <p class="text-sm text-gray-700 mb-2">{{ $log['message'] }}</p>

                            {{-- Error message --}}
                            @if(!empty($log['error_message']) && $log['error_message'] !== $log['message'])
                                <div class="text-xs text-red-600 bg-red-100 p-2 rounded mt-2">
                                    <strong>Error:</strong> {{ $log['error_message'] }}
                                </div>
                            @endif

                            {{-- Metrics --}}
                            <div class="flex flex-wrap gap-4 text-xs text-gray-500 mt-2">
                                @if(!empty($log['execution_time']))
                                    <span>⏱️ {{ number_format($log['execution_time'], 3) }}s</span>
                                @endif

                                @if(!empty($log['memory_usage']))
                                    <span>📊 {{ number_format($log['memory_usage'] / 1024 / 1024, 1) }}MB</span>
                                @endif

                                @if(!empty($log['metrics']))
                                    @if(isset($log['metrics']['file_size']['human']))
                                        <span>📁 {{ $log['metrics']['file_size']['human'] }}</span>
                                    @endif

                                    @if(isset($log['metrics']['api']['status_code']))
                                        <span class="
                                            @if($log['metrics']['api']['status_code'] >= 400) text-red-600
                                            @elseif($log['metrics']['api']['status_code'] >= 300) text-yellow-600
                                            @else text-green-600 @endif
                                        ">
                                            🌐 {{ $log['metrics']['api']['status_code'] }}
                                        </span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-8 text-gray-500">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <p class="mt-2">No logs match the current filters</p>
                    </div>
                @endforelse
            </div>
        @else
            <div class="text-center py-8 text-gray-500">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <p class="mt-2">No processing logs available</p>
                <button
                    wire:click="fetchLogs"
                    class="mt-2 text-blue-600 hover:text-blue-800"
                >
                    Try fetching logs
                </button>
            </div>
        @endif

        {{-- Last updated --}}
        @if($lastFetch)
            <div class="mt-4 text-xs text-gray-500 text-center">
                Last updated: {{ $lastFetch->diffForHumans() }}
            </div>
        @endif
    </div>

    <script>
        function logsViewer() {
            return {
                expanded: @entangle('expanded'),
                autoRefresh: @entangle('autoRefresh'),
                refreshInterval: null,

                init() {
                    this.setupAutoRefresh();
                },

                toggleExpanded() {
                    this.expanded = !this.expanded;
                    if (this.expanded) {
                        this.$wire.fetchLogs();
                    }
                },

                setupAutoRefresh() {
                    this.$watch('autoRefresh', (value) => {
                        if (value) {
                            this.startAutoRefresh();
                        } else {
                            this.stopAutoRefresh();
                        }
                    });

                    if (this.autoRefresh) {
                        this.startAutoRefresh();
                    }
                },

                startAutoRefresh() {
                    this.stopAutoRefresh(); // Clear any existing interval
                    this.refreshInterval = setInterval(() => {
                        if (this.expanded && this.autoRefresh) {
                            this.$wire.fetchLogs();
                        }
                    }, @json($refreshInterval));
                },

                stopAutoRefresh() {
                    if (this.refreshInterval) {
                        clearInterval(this.refreshInterval);
                        this.refreshInterval = null;
                    }
                }
            }
        }
    </script>
</div>