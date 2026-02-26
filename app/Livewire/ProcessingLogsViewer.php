<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Contracts\ProcessingStatusContract;
use App\Data\ProcessingLogEntry;
use App\Livewire\Traits\HasConditionalLogging;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Component;

class ProcessingLogsViewer extends Component
{
    use HasConditionalLogging;

    public string $processingId;

    public bool $autoRefresh = true;

    public bool $expanded = false;

    public int $refreshInterval = 2000; // milliseconds

    public int $logLimit = 20;

    // Processing status data
    /** @var array<string, mixed> */
    public array $statusData = [];

    /** @var array<int, array<string, mixed>> */
    public array $logs = [];

    /** @var array<string, mixed> */
    public array $performanceMetrics = [];

    public ?Carbon $lastFetch = null;

    // UI state
    public string $filterLevel = 'all';

    public string $filterStep = 'all';

    public bool $showMetrics = true;

    /** @var array<string, string> */
    protected array $rules = [
        'processingId' => 'required|string',
        'autoRefresh' => 'boolean',
        'logLimit' => 'integer|min:5|max:100',
        'filterLevel' => 'string|in:all,error,warning,info,debug',
        'filterStep' => 'string',
    ];

    public function mount(
        string $processingId,
        bool $autoRefresh = true,
        bool $expanded = false,
        int $logLimit = 20
    ): void {
        $this->processingId = $processingId;
        // Disable auto-refresh in testing environment to prevent test hangs
        $this->autoRefresh = app()->environment('testing') ? false : $autoRefresh;
        $this->expanded = $expanded;
        $this->logLimit = $logLimit;
        $this->lastFetch = now();

        $this->logDebug('ProcessingLogsViewer: Component mounting', [
            'processing_id' => $processingId,
            'auto_refresh' => $autoRefresh,
            'expanded' => $expanded,
        ]);

        $this->fetchLogs();
    }

    public function fetchLogs(): void
    {
        try {
            // Find the appropriate controller to handle this processing ID
            $controller = $this->findControllerForProcessingId($this->processingId);

            if (! $controller) {
                $this->logWarning('ProcessingLogsViewer: No controller found for processing ID', [
                    'processing_id' => $this->processingId,
                ]);

                return;
            }

            // Get processing status with logs
            $response = $controller->getProcessingStatusWithLogs(
                $this->processingId,
                true,
                $this->logLimit
            );

            if ($response->found) {
                $this->statusData = [
                    'status' => $response->status,
                    'current_step' => $response->currentStep,
                    'progress_percentage' => $response->progressPercentage,
                    'error_message' => $response->errorMessage,
                    'started_at' => $response->startedAt?->toISOString(),
                    'updated_at' => $response->updatedAt?->toISOString(),
                    'estimated_completion' => $response->estimatedCompletion,
                ];

                // Update logs
                if ($response->recentLogs && ! $response->recentLogs->isEmpty()) {
                    $this->logs = $response->recentLogs->entries
                        ->map(fn (ProcessingLogEntry $entry) => [
                            'step' => $entry->step,
                            'level' => $entry->level,
                            'message' => $entry->message,
                            'timestamp' => $entry->timestamp,
                            'execution_time' => $entry->executionTime,
                            'memory_usage' => $entry->memoryUsage,
                            'error_message' => $entry->errorMessage,
                            'metrics' => $entry->metrics,
                        ])
                        ->toArray();
                }

                // Update performance metrics
                if ($response->performanceMetrics) {
                    $this->performanceMetrics = $response->performanceMetrics;
                }

                $this->lastFetch = now();
            } else {
                $this->logWarning('ProcessingLogsViewer: Processing record not found', [
                    'processing_id' => $this->processingId,
                ]);
            }
        } catch (\Exception $e) {
            $this->logError('ProcessingLogsViewer: Error fetching logs', [
                'processing_id' => $this->processingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function toggleExpanded(): void
    {
        $this->expanded = ! $this->expanded;

        // Fetch logs when expanding for the first time
        if ($this->expanded && empty($this->logs)) {
            $this->fetchLogs();
        }
    }

    public function toggleAutoRefresh(): void
    {
        $this->autoRefresh = ! $this->autoRefresh;

        $this->logDebug('ProcessingLogsViewer: Auto refresh toggled', [
            'processing_id' => $this->processingId,
            'auto_refresh' => $this->autoRefresh,
        ]);
    }

    public function refreshLogs(): void
    {
        $this->fetchLogs();
    }

    public function updateLogLimit(): void
    {
        $this->validate(['logLimit' => 'integer|min:5|max:100']);
        $this->fetchLogs();
    }

    public function updateFilter(): void
    {
        // Filters are applied in the computed property, no need to refetch
        $this->logDebug('ProcessingLogsViewer: Filter updated', [
            'processing_id' => $this->processingId,
            'filter_level' => $this->filterLevel,
            'filter_step' => $this->filterStep,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>|non-empty-array<string, mixed>>
     */
    public function getFilteredLogsProperty(): Collection
    {
        $logs = $this->logs;

        // Filter by level
        if ($this->filterLevel !== 'all') {
            $logs = array_values(array_filter(
                $logs,
                fn (array $log): bool => ($log['level'] ?? null) === $this->filterLevel
            ));
        }

        // Filter by step
        if ($this->filterStep !== 'all' && $this->filterStep !== '') {
            $logs = array_values(array_filter(
                $logs,
                fn (array $log): bool => ($log['step'] ?? null) === $this->filterStep
            ));
        }

        return collect($logs);
    }

    /**
     * @return array<int, string>
     */
    public function getAvailableStepsProperty(): array
    {
        return collect($this->logs)
            ->pluck('step')
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    public function getCurrentStepProperty(): string
    {
        return $this->statusData['current_step'] ?? 'Unknown';
    }

    public function getProcessingTimeProperty(): string
    {
        if (! isset($this->performanceMetrics['total_execution_time'])) {
            return 'N/A';
        }

        $seconds = (float) $this->performanceMetrics['total_execution_time'];

        if ($seconds < 60) {
            return number_format($seconds, 1).'s';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds - ($minutes * 60);

        return "{$minutes}m ".round($remainingSeconds).'s';
    }

    public function getMemoryPeakProperty(): string
    {
        if (! isset($this->performanceMetrics['peak_memory_usage'])) {
            return 'N/A';
        }

        $bytes = (int) $this->performanceMetrics['peak_memory_usage'];

        return $this->formatBytes($bytes);
    }

    public function getStatusColorProperty(): string
    {
        return match ($this->statusData['status'] ?? 'unknown') {
            'completed' => 'green',
            'processing', 'pending' => 'blue',
            'failed' => 'red',
            'cancelled' => 'gray',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public function getLevelColorProperty(): array
    {
        return [
            'error' => 'red',
            'warning' => 'yellow',
            'info' => 'blue',
            'debug' => 'gray',
        ];
    }

    private function findControllerForProcessingId(string $processingId): ?ProcessingStatusContract
    {
        // Use the unified MediaController
        $mediaController = app(\App\Http\Controllers\Api\MediaController::class);
        if ($mediaController->canHandle($processingId)) {
            return $mediaController;
        }

        return null;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
    }

    #[Lazy]
    public function render(): View
    {
        return view('livewire.processing-logs-viewer');
    }

    public function placeholder(): View
    {
        return view('livewire.components.lazy-placeholder');
    }
}
