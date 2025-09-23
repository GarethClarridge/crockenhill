# Enhanced Processing Logs Display Plan (Revised)

## Overview
Implement a comprehensive processing logs display system that leverages the existing robust logging infrastructure while adhering to Laravel best practices and CLAUDE.md architectural guidelines. The system will provide detailed, real-time visibility into all processing types through a properly architected service layer.

## Comprehensive Processing Analysis

### All Processing Types Covered
1. **Sermon Audio Processing** (`SermonProcessingLog` + `SermonProcessingLogger`)
   - File validation and preparation
   - Audio transcription (OpenAI Whisper API calls)
   - AI analysis for metadata extraction
   - Sermon record creation
2. **Livestream Video Processing** (`LivestreamProcessingLog` + `LivestreamProcessingLogger`)
   - Video file analysis and RMS audio analysis
   - Segment identification and classification
   - Sermon extraction via FFmpeg
   - Audio processing of extracted segments
3. **Direct Video Processing** (Video files processed as sermons)
   - Audio extraction from video files
   - Full audio transcription and analysis
   - Video file preservation and linking

### Current Logging Infrastructure Analysis
All processing types use extensive step-by-step logging with:
- **Performance metrics**: Memory usage, execution time, file sizes
- **API call tracking**: Response times, status codes, error details
- **File operations**: Storage operations, conversions, validations
- **Error handling**: Detailed stack traces, categorized error types
- **Progress tracking**: Real-time step updates with timestamps

## ✅ Architecturally Sound Implementation

### 1. Service Layer Architecture (SOLID Principles)

#### ProcessingLogService (New Service)
```php
<?php

namespace App\Services;

use App\Contracts\ProcessingLogContract;
use App\Data\ProcessingLogCollection;
use App\Data\ProcessingLogEntry;
use Carbon\Carbon;

class ProcessingLogService implements ProcessingLogContract
{
    public function __construct(
        private readonly SermonProcessingLogger $sermonLogger,
        private readonly LivestreamProcessingLogger $livestreamLogger
    ) {}

    public function getProcessingLogs(string $processingId, int $limit = 50): ProcessingLogCollection
    {
        // Parse log files and database records for comprehensive log data
        return $this->aggregateLogsFromAllSources($processingId, $limit);
    }

    public function getLogsSince(string $processingId, Carbon $since): ProcessingLogCollection
    {
        // Incremental log fetching for real-time updates
        return $this->getLogsAfterTimestamp($processingId, $since);
    }

    public function getLogsByStep(string $processingId, string $step): ProcessingLogCollection
    {
        // Filter logs by specific processing step
        return $this->filterLogsByStep($processingId, $step);
    }
}
```

#### Enhanced ProcessingStatusContract (Proper API Extension)
```php
<?php

namespace App\Contracts;

use App\Data\StandardProcessingResponse;

interface ProcessingStatusContract
{
    // Existing methods maintained for backwards compatibility
    public function getProcessingStatus(string $processingId): StandardProcessingResponse;
    public function cancelProcessing(string $processingId): array;
    public function canHandle(string $processingId): bool;

    // New method for unified log access
    public function getProcessingStatusWithLogs(
        string $processingId,
        bool $includeLogs = false,
        int $logLimit = 20
    ): StandardProcessingResponse;
}
```

#### Enhanced StandardProcessingResponse (Unified API Pattern)
```php
<?php

namespace App\Data;

use App\Data\ProcessingLogCollection;

class StandardProcessingResponse
{
    public function __construct(
        // ... existing properties
        public readonly ?ProcessingLogCollection $recentLogs = null,
        public readonly ?array $performanceMetrics = null,
        public readonly ?array $errorHistory = null
    ) {}

    public static function withLogs(
        // ... existing parameters
        ?ProcessingLogCollection $logs = null,
        ?array $metrics = null
    ): self {
        return new self(
            // ... existing assignments
            recentLogs: $logs,
            performanceMetrics: $metrics
        );
    }
}
```

### 2. Data Transfer Objects (Type Safety)

#### ProcessingLogEntry
```php
<?php

namespace App\Data;

use Carbon\Carbon;

readonly class ProcessingLogEntry
{
    public function __construct(
        public string $step,
        public string $level,
        public string $message,
        public Carbon $timestamp,
        public ?array $metrics = null,
        public ?string $errorMessage = null,
        public ?array $context = null,
        public ?float $executionTime = null,
        public ?int $memoryUsage = null
    ) {}
}
```

#### ProcessingLogCollection
```php
<?php

namespace App\Data;

use Illuminate\Support\Collection;

class ProcessingLogCollection
{
    public function __construct(
        public readonly Collection $entries,
        public readonly int $totalCount,
        public readonly ?string $nextCursor = null,
        public readonly ?array $summary = null
    ) {}

    public function toArray(): array
    {
        return [
            'entries' => $this->entries->map->toArray(),
            'total_count' => $this->totalCount,
            'next_cursor' => $this->nextCursor,
            'summary' => $this->summary,
        ];
    }
}
```

### 3. Controller Implementation (Single Responsibility)

#### Extend Existing Controllers (Minimal Changes)
```php
<?php

// AutomatedSermonController.php
public function getProcessingStatusWithLogs(
    string $processingId,
    bool $includeLogs = false,
    int $logLimit = 20
): StandardProcessingResponse {

    $baseStatus = $this->getProcessingStatus($processingId);

    if (!$includeLogs || !$baseStatus->found) {
        return $baseStatus;
    }

    $logs = $this->processingLogService->getProcessingLogs($processingId, $logLimit);
    $metrics = $this->processingLogService->getPerformanceMetrics($processingId);

    return StandardProcessingResponse::withLogs(
        // ... base status data
        logs: $logs,
        metrics: $metrics
    );
}
```

### 4. Frontend Component Architecture (Livewire Best Practices)

#### Separate Livewire Components
```php
<?php

// MediaUpload.php - Focused on upload functionality
class MediaUpload extends Component
{
    public ?string $processingId = null;
    // Upload-specific properties only

    public function render()
    {
        return view('livewire.media-upload');
    }
}

// ProcessingLogsViewer.php - Dedicated to log display
class ProcessingLogsViewer extends Component
{
    public string $processingId;
    public array $logs = [];
    public bool $autoRefresh = true;
    public Carbon $lastFetch;

    public function fetchLogs(): void
    {
        $response = $this->getProcessingStatus();
        if ($response->recentLogs) {
            $this->logs = $response->recentLogs->entries->toArray();
        }
    }
}
```

#### TALL Stack Integration
```blade
{{-- media-upload.blade.php --}}
<div>
    <!-- Upload UI -->
    <div class="upload-area">
        <!-- Upload form -->
    </div>

    <!-- Processing Status & Logs -->
    @if($processingId)
        <livewire:processing-logs-viewer
            :processing-id="$processingId"
            :auto-refresh="true"
        />
    @endif
</div>

{{-- processing-logs-viewer.blade.php --}}
<div x-data="logsViewer()" x-init="init()">
    <!-- Collapsible Logs Section -->
    <div class="bg-white rounded-lg shadow-sm border">
        <div class="flex items-center justify-between p-4 border-b">
            <h3 class="text-lg font-semibold">Processing Details</h3>
            <button @click="expanded = !expanded"
                    class="text-gray-500 hover:text-gray-700">
                <span x-show="!expanded">Show Logs</span>
                <span x-show="expanded">Hide Logs</span>
            </button>
        </div>

        <div x-show="expanded" x-transition class="p-4">
            <!-- Performance Summary -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-blue-50 p-3 rounded">
                    <div class="text-sm text-blue-600">Processing Time</div>
                    <div class="text-lg font-semibold">{{ $processingTime }}s</div>
                </div>
                <div class="bg-green-50 p-3 rounded">
                    <div class="text-sm text-green-600">Memory Peak</div>
                    <div class="text-lg font-semibold">{{ $memoryPeak }}MB</div>
                </div>
                <div class="bg-purple-50 p-3 rounded">
                    <div class="text-sm text-purple-600">Current Step</div>
                    <div class="text-lg font-semibold">{{ $currentStep }}</div>
                </div>
            </div>

            <!-- Processing Timeline -->
            <div class="space-y-3">
                @foreach($logs as $log)
                    <div class="flex items-start space-x-3 p-3 border rounded
                                @if($log['level'] === 'error') bg-red-50 border-red-200
                                @elseif($log['level'] === 'warning') bg-yellow-50 border-yellow-200
                                @else bg-gray-50 border-gray-200 @endif">

                        <div class="flex-shrink-0">
                            @if($log['level'] === 'error')
                                <x-heroicon-s-x-circle class="w-5 h-5 text-red-500" />
                            @elseif($log['level'] === 'warning')
                                <x-heroicon-s-exclamation-triangle class="w-5 h-5 text-yellow-500" />
                            @else
                                <x-heroicon-s-check-circle class="w-5 h-5 text-green-500" />
                            @endif
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-medium">{{ $log['step'] }}</h4>
                                <span class="text-xs text-gray-500">
                                    {{ $log['timestamp']->diffForHumans() }}
                                </span>
                            </div>
                            <p class="text-sm text-gray-600 mt-1">{{ $log['message'] }}</p>

                            @if($log['execution_time'])
                                <div class="text-xs text-gray-500 mt-2">
                                    Execution time: {{ $log['execution_time'] }}ms
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <script>
        function logsViewer() {
            return {
                expanded: false,

                init() {
                    if (this.$wire.autoRefresh) {
                        setInterval(() => {
                            this.$wire.fetchLogs();
                        }, 2000);
                    }
                }
            }
        }
    </script>
</div>
```

## Implementation Benefits

### ✅ Architectural Compliance
- **SOLID Principles**: Single responsibility, dependency injection, interface segregation
- **Service Layer**: Dedicated `ProcessingLogService` with proper abstraction
- **API Consistency**: Extends existing `ProcessingStatusContract` pattern
- **Component Separation**: Dedicated `ProcessingLogsViewer` component

### ✅ Comprehensive Coverage
- **All Processing Types**: Sermon audio, livestream video, direct video processing
- **Complete Log Data**: Performance metrics, API calls, file operations, errors
- **Real-time Updates**: Incremental log fetching with polling
- **Error Categorization**: Structured error analysis and reporting

### ✅ Performance Optimizations
- **Incremental Updates**: Fetch only new logs since last check
- **Configurable Limits**: Pagination and log entry limits
- **Component Lifecycle**: Proper cleanup and polling management
- **Caching Strategy**: Log aggregation caching for performance

### ✅ User Experience
- **Progressive Disclosure**: Collapsible log sections
- **Real-time Feedback**: Live progress updates during processing
- **Performance Insights**: Memory usage, execution times, step progress
- **Error Transparency**: Clear error messages with actionable context

## Files to Modify

### Backend (Service Layer)
- `app/Services/ProcessingLogService.php` - **NEW** (Core service)
- `app/Contracts/ProcessingLogContract.php` - **NEW** (Interface)
- `app/Data/ProcessingLogEntry.php` - **NEW** (DTO)
- `app/Data/ProcessingLogCollection.php` - **NEW** (DTO)
- `app/Contracts/ProcessingStatusContract.php` - **EXTEND** (Add logs method)
- `app/Data/StandardProcessingResponse.php` - **EXTEND** (Add logs properties)
- `app/Http/Controllers/AutomatedSermonController.php` - **EXTEND** (Implement new contract method)
- `app/Http/Controllers/Api/LivestreamProcessingController.php` - **EXTEND** (Implement new contract method)

### Frontend (Component Layer)
- `app/Livewire/ProcessingLogsViewer.php` - **NEW** (Dedicated component)
- `resources/views/livewire/processing-logs-viewer.blade.php` - **NEW** (Component view)
- `resources/views/livewire/media-upload.blade.php` - **EXTEND** (Include logs viewer)

### Tests
- `tests/Unit/Services/ProcessingLogServiceTest.php` - **NEW**
- `tests/Feature/ProcessingLogsApiTest.php` - **NEW**
- `tests/Feature/ProcessingLogsViewerTest.php` - **NEW**

## Conclusion

This revised implementation properly adheres to CLAUDE.md architectural principles while providing comprehensive coverage of all processing types. The service layer architecture ensures maintainability and follows Laravel best practices, while the component separation enables optimal performance and user experience. The system leverages the existing robust logging infrastructure without requiring database schema changes, making it a minimal-impact, high-value addition to the processing pipeline.