<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Actions\GetMediaProcessingStatus;
use App\Data\ProcessingLogCollection;
use App\Data\ProcessingLogEntry;
use App\Data\StandardProcessingResponse;
use App\Livewire\ProcessingLogsViewer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingLogsViewerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $processingId = 'test-processing-id-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    protected function mockStatusQueryResponse(string $status = 'processing', int $progress = 50, array $logEntries = [])
    {
        $entries = collect($logEntries)->map(fn ($data) => new ProcessingLogEntry(
            step: $data['step'] ?? 'test-step',
            level: $data['level'] ?? 'info',
            message: $data['message'] ?? 'Test log message',
            timestamp: $data['timestamp'] ?? now(),
            executionTime: $data['execution_time'] ?? 0.1,
            memoryUsage: $data['memory_usage'] ?? 1024,
            errorMessage: $data['error_message'] ?? null,
            metrics: $data['metrics'] ?? []
        ));

        $logCollection = new ProcessingLogCollection(
            entries: $entries,
            totalCount: $entries->count()
        );

        $response = StandardProcessingResponse::withLogs(
            processingId: $this->processingId,
            status: $status,
            currentStep: 'Testing',
            progressPercentage: $progress,
            errorMessage: null,
            sermonId: null,
            sermonUrl: null,
            startedAt: now()->subMinutes(5),
            updatedAt: now(),
            estimatedCompletion: null,
            logs: $logCollection,
            metrics: [
                'total_execution_time' => 300,
                'peak_memory_usage' => 52428800, // 50MB
            ]
        );

        $mockStatusQuery = $this->createMock(GetMediaProcessingStatus::class);
        $mockStatusQuery->method('getWithLogs')
            ->willReturnCallback(function (string $processingId, int $logLimit) use ($response): StandardProcessingResponse {
                $this->assertSame($this->processingId, $processingId);
                $this->assertGreaterThanOrEqual(5, $logLimit);

                return $response;
            });

        $this->app->instance(GetMediaProcessingStatus::class, $mockStatusQuery);

        return $response;
    }

    #[Test]
    public function it_can_clear_filters(): void
    {
        $this->actingAs($this->admin);
        $this->mockStatusQueryResponse('processing', 50, [
            ['message' => 'Error log', 'level' => 'error', 'step' => 'audio'],
        ]);

        Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId])
            ->set('filterLevel', 'error')
            ->set('filterStep', 'audio')
            ->assertSet('hasActiveFilters', true)
            ->call('clearFilters')
            ->assertSet('filterLevel', 'all')
            ->assertSet('filterStep', 'all')
            ->assertSet('hasActiveFilters', false);
    }

    #[Test]
    public function it_renders_successfully_and_fetches_logs()
    {
        $this->actingAs($this->admin);
        $this->mockStatusQueryResponse('processing', 45, [
            ['message' => 'First step started', 'step' => 'init'],
            ['message' => 'Processing audio', 'step' => 'audio'],
        ]);

        Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId])
            ->assertStatus(200)
            ->assertSee('First step started')
            ->assertSee('Processing audio')
            ->assertSet('statusData.progress_percentage', 45);
    }

    #[Test]
    public function it_can_toggle_expansion()
    {
        $this->actingAs($this->admin);
        $this->mockStatusQueryResponse();

        Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId, 'expanded' => false])
            ->assertSet('expanded', false)
            ->call('toggleExpanded')
            ->assertSet('expanded', true)
            ->call('toggleExpanded')
            ->assertSet('expanded', false);
    }

    #[Test]
    public function it_can_toggle_auto_refresh()
    {
        $this->actingAs($this->admin);
        $this->mockStatusQueryResponse();

        // Note: autoRefresh is disabled by default in testing environment in the component's mount
        $component = Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId]);

        $initialValue = $component->get('autoRefresh');

        $component->set('autoRefresh', ! $initialValue)
            ->assertSet('autoRefresh', ! $initialValue);
    }

    #[Test]
    public function it_filters_logs_by_level()
    {
        $this->actingAs($this->admin);
        $this->mockStatusQueryResponse('processing', 50, [
            ['message' => 'Info log', 'level' => 'info'],
            ['message' => 'Error log', 'level' => 'error'],
        ]);

        $test = Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId])
            ->set('filterLevel', 'error')
            ->call('updateFilter');

        $filteredLogs = $test->get('filteredLogs');
        $this->assertCount(1, $filteredLogs);
        $this->assertEquals('error', $filteredLogs->first()['level']);
    }

    #[Test]
    public function it_filters_logs_by_step()
    {
        $this->actingAs($this->admin);
        $this->mockStatusQueryResponse('processing', 50, [
            ['message' => 'Init message', 'step' => 'init'],
            ['message' => 'Audio message', 'step' => 'audio'],
        ]);

        $test = Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId])
            ->set('filterStep', 'audio')
            ->call('updateFilter');

        $filteredLogs = $test->get('filteredLogs');
        $this->assertCount(1, $filteredLogs);
        $this->assertEquals('audio', $filteredLogs->first()['step']);
    }

    #[Test]
    public function it_calculates_correct_status_color()
    {
        $this->actingAs($this->admin);

        // Test success for completed
        $this->mockStatusQueryResponse('completed');
        Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId])
            ->assertSet('statusColor', 'success');

        // Test danger for failed
        $this->mockStatusQueryResponse('failed');
        Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId])
            ->assertSet('statusColor', 'danger');
    }

    #[Test]
    public function it_formats_processing_time_correctly()
    {
        $this->actingAs($this->admin);

        // 300 seconds = 5 minutes
        $this->mockStatusQueryResponse();
        Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId])
            ->assertSet('processingTime', '5m 0s');
    }

    #[Test]
    public function it_validates_log_limit_boundaries(): void
    {
        $this->actingAs($this->admin);
        $this->mockStatusQueryResponse();

        $component = Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId]);

        // Too small
        $component->set('logLimit', 2)
            ->call('updateLogLimit')
            ->assertHasErrors(['logLimit']);

        // Too large
        $component->set('logLimit', 150)
            ->call('updateLogLimit')
            ->assertHasErrors(['logLimit']);

        // Valid
        $component->set('logLimit', 50)
            ->call('updateLogLimit')
            ->assertHasNoErrors();
    }

    #[Test]
    public function it_formats_memory_peak_correctly(): void
    {
        $this->actingAs($this->admin);

        // Default mock has 50 MB (52428800 bytes)
        // Standardized Number::fileSize(..., precision: 2) includes decimals for consistency
        $this->mockStatusQueryResponse();
        Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId])
            ->assertSet('memoryPeak', '50.00 MB');
    }

    #[Test]
    public function expanded_state_is_owned_by_livewire_and_toggles_correctly(): void
    {
        $this->actingAs($this->admin);
        $this->mockStatusQueryResponse();

        Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId, 'expanded' => false])
            ->assertSet('expanded', false)
            ->call('toggleExpanded')
            ->assertSet('expanded', true)
            ->call('toggleExpanded')
            ->assertSet('expanded', false);
    }

    #[Test]
    public function auto_refresh_state_is_owned_by_livewire_and_toggles_correctly(): void
    {
        $this->actingAs($this->admin);
        $this->mockStatusQueryResponse();

        // In testing env, autoRefresh is forced false in mount(); the checkbox is the sole
        // owner of the property (wire:model.live), so toggling the property in the test
        // simulates what the UI does and produces a single net state transition per click.
        Livewire::test(ProcessingLogsViewer::class, ['processingId' => $this->processingId, 'autoRefresh' => true])
            ->assertSet('autoRefresh', false) // overridden by testing env guard
            ->set('autoRefresh', true)
            ->assertSet('autoRefresh', true)
            ->set('autoRefresh', false)
            ->assertSet('autoRefresh', false);
    }

    #[Test]
    public function it_handles_missing_processing_id_gracefully(): void
    {
        $this->actingAs($this->admin);

        $mockStatusQuery = $this->createMock(GetMediaProcessingStatus::class);
        $mockStatusQuery->expects($this->once())
            ->method('getWithLogs')
            ->with('non-existent-id', 20)
            ->willReturn(StandardProcessingResponse::notFound());
        $this->app->instance(GetMediaProcessingStatus::class, $mockStatusQuery);

        $component = Livewire::test(ProcessingLogsViewer::class, ['processingId' => 'non-existent-id']);

        $component->assertStatus(200)
            ->assertSet('processingId', 'non-existent-id')
            ->assertSet('logs', [])
            ->assertSet('statusData', []);
    }
}
