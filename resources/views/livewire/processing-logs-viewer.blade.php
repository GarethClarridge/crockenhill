<div x-data="logsViewerAutoRefresh()" x-init="init()" class="bg-white rounded-lg shadow-sm border">
    {{-- Header --}}
    <div class="flex items-center justify-between p-4 border-b">
        <div class="flex items-center space-x-3">
            <h3 class="text-lg font-semibold text-gray-900">Processing Details</h3>
            <x-badge :variant="$this->statusColor" :pulse="in_array($statusData['status'] ?? '', ['processing', 'pending'])">
                {{ ucfirst($statusData['status'] ?? 'Unknown') }}
            </x-badge>
        </div>

        <div class="flex items-center space-x-2">
            {{-- Auto-refresh toggle --}}
            <label class="flex items-center text-sm text-gray-600 mr-2">
                <input
                    type="checkbox"
                    wire:model.live="autoRefresh"
                    class="rounded border-gray-300 text-cbc-teal focus:ring-cbc-teal focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
                >
                <span class="ml-1.5">Auto-refresh</span>
            </label>

            {{-- Manual refresh button --}}
            <x-form-button
                wire:click="refreshLogs"
                variant="ghost"
                size="xs"
                icon="arrow-path"
                aria-label="Refresh logs"
            />

            {{-- Toggle expanded --}}
            <button
                wire:click="toggleExpanded"
                class="flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded px-2 py-1 transition-colors"
                :aria-expanded="$wire.expanded ? 'true' : 'false'"
                aria-controls="processing-logs-content"
            >
                <span x-show="!$wire.expanded">Show Logs</span>
                <span x-show="$wire.expanded">Hide Logs</span>
                <x-heroicon-o-chevron-down x-show="!$wire.expanded" class="ml-1 h-4 w-4" />
                <x-heroicon-o-chevron-up x-show="$wire.expanded" class="ml-1 h-4 w-4" x-cloak />
            </button>
        </div>
    </div>

    <div id="processing-logs-content" x-show="$wire.expanded" x-transition class="p-4" aria-busy="false" wire:loading.attr="aria-busy" wire:target="refreshLogs, fetchLogs">
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
                {{-- Clear filters --}}
                <div class="flex items-center"
                     x-show="$wire.hasActiveFilters"
                     x-cloak
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95">
                    <x-form-button variant="ghost" size="xs" icon="x-mark" wire:click="clearFilters">
                        Clear Filters
                    </x-form-button>
                </div>

                {{-- Log limit --}}
                <div class="flex items-center space-x-2">
                    <label for="log-limit" class="text-sm font-medium text-gray-700">Limit:</label>
                    <select
                        id="log-limit"
                        wire:model.live="logLimit"
                        wire:change="updateLogLimit"
                        class="text-sm border-gray-300 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
                    >
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>

                {{-- Level filter --}}
                <div class="flex items-center space-x-2">
                    <label for="filter-level" class="text-sm font-medium text-gray-700">Level:</label>
                    <select
                        id="filter-level"
                        wire:model.live="filterLevel"
                        wire:change="updateFilter"
                        class="text-sm border-gray-300 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
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
                        <label for="filter-step" class="text-sm font-medium text-gray-700">Step:</label>
                        <select
                            id="filter-step"
                            wire:model.live="filterStep"
                            wire:change="updateFilter"
                            class="text-sm border-gray-300 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
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
                                <x-heroicon-s-x-circle class="w-5 h-5 text-red-500" aria-hidden="true" />
                            @elseif($log['level'] === 'warning')
                                <x-heroicon-s-exclamation-triangle class="w-5 h-5 text-yellow-500" aria-hidden="true" />
                            @elseif($log['level'] === 'info')
                                <x-heroicon-s-information-circle class="w-5 h-5 text-blue-500" aria-hidden="true" />
                            @else
                                <x-heroicon-s-check-circle class="w-5 h-5 text-gray-500" aria-hidden="true" />
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
                    class="mt-2 text-blue-600 hover:text-blue-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded"
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

    @script
    <script>
        window.logsViewerAutoRefresh = function logsViewerAutoRefresh() {
            return {
                refreshInterval: null,

                init() {
                    this.setupAutoRefresh();
                },

                destroy() {
                    this.stopAutoRefresh();
                },

                setupAutoRefresh() {
                    this.$watch('$wire.autoRefresh', (value) => {
                        if (value && $wire.expanded) {
                            this.startAutoRefresh();
                        } else {
                            this.stopAutoRefresh();
                        }
                    });

                    this.$watch('$wire.expanded', (value) => {
                        if (value && $wire.autoRefresh) {
                            this.startAutoRefresh();
                        } else {
                            this.stopAutoRefresh();
                        }
                    });

                    if ($wire.autoRefresh && $wire.expanded) {
                        this.startAutoRefresh();
                    }
                },

                startAutoRefresh() {
                    this.stopAutoRefresh();
                    this.refreshInterval = setInterval(() => {
                        $wire.fetchLogs();
                    }, @json($refreshInterval));
                },

                stopAutoRefresh() {
                    if (this.refreshInterval) {
                        clearInterval(this.refreshInterval);
                        this.refreshInterval = null;
                    }
                },
            }
        }
    </script>
    @endscript
</div>
