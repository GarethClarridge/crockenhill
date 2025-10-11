<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Livewire\ProcessingLogsViewer;
use App\Models\MediaProcessingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ProcessingLogsViewerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $originalLogFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
        ]);

        // Create unique log file for this test to avoid parallel test conflicts
        $testId = uniqid('test_', true);
        $this->originalLogFile = storage_path("logs/laravel-{$testId}.log");
        File::put($this->originalLogFile, '');

        // Bind custom ProcessingLogService with unique log file
        $this->app->bind(\App\Services\ProcessingLogService::class, function () {
            return new \App\Services\ProcessingLogService($this->originalLogFile);
        });
    }

    protected function tearDown(): void
    {
        // Clean up unique test log file
        if (File::exists($this->originalLogFile)) {
            File::delete($this->originalLogFile);
        }

        parent::tearDown();
    }

    public function test_component_renders_with_processing_id(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
            'current_step' => 'transcribing',
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
            ]);

        $component->assertStatus(200)
            ->assertSee('Processing Details')
            ->assertSee('Processing')
            ->assertSet('processingId', $processingId)
            ->assertSet('autoRefresh', false) // Disabled in testing environment
            ->assertSet('expanded', false);
    }

    public function test_component_fetches_logs_on_mount(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $logEntries = [
            '[2024-01-01 10:00:00] local.INFO: Processing step: initialization - started {"processing_id":"'.$processingId.'","step":"initialization","status":"started"}',
            '[2024-01-01 10:00:05] local.ERROR: Processing step: transcription - failed {"processing_id":"'.$processingId.'","step":"transcription","status":"failed","error_message":"API timeout"}',
        ];

        File::put($this->originalLogFile, implode("\n", $logEntries));

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
            ]);

        $component->assertSet('statusData.status', 'processing')
            ->assertCount('logs', 2);

        // Check that logs are properly parsed
        $logs = $component->get('logs');
        $this->assertEquals('transcription', $logs[0]['step']);
        $this->assertEquals('error', $logs[0]['level']);
        $this->assertEquals('API timeout', $logs[0]['error_message']);
    }

    public function test_component_can_toggle_expanded_state(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
                'expanded' => false,
            ]);

        $component->assertSet('expanded', false)
            ->call('toggleExpanded')
            ->assertSet('expanded', true)
            ->call('toggleExpanded')
            ->assertSet('expanded', false);
    }

    public function test_component_can_toggle_auto_refresh(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
            ]);

        // In testing environment, autoRefresh starts as false
        $component->assertSet('autoRefresh', false)
            ->call('toggleAutoRefresh')
            ->assertSet('autoRefresh', true)
            ->call('toggleAutoRefresh')
            ->assertSet('autoRefresh', false);
    }

    public function test_component_can_refresh_logs_manually(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        // Initial log entry
        $logEntries = [
            '[2024-01-01 10:00:00] local.INFO: Initial log {"processing_id":"'.$processingId.'","step":"initialization"}',
        ];

        File::put($this->originalLogFile, implode("\n", $logEntries));

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
            ]);

        $component->assertCount('logs', 1);

        // Add more log entries
        $logEntries[] = '[2024-01-01 10:00:05] local.INFO: New log {"processing_id":"'.$processingId.'","step":"transcription"}';
        File::put($this->originalLogFile, implode("\n", $logEntries));

        // Manually refresh
        $component->call('refreshLogs')
            ->assertCount('logs', 2);
    }

    public function test_component_can_update_log_limit(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        // Create 10 log entries
        $logEntries = [];
        for ($i = 0; $i < 10; $i++) {
            $logEntries[] = '[2024-01-01 10:00:'.sprintf('%02d', $i).'] local.INFO: Step '.$i.' {"processing_id":"'.$processingId.'","step":"step_'.$i.'"}';
        }

        File::put($this->originalLogFile, implode("\n", $logEntries));

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
                'logLimit' => 20,
            ]);

        $component->assertCount('logs', 10); // All entries returned

        // Change log limit to 5
        $component->set('logLimit', 5)
            ->call('updateLogLimit')
            ->assertCount('logs', 5);
    }

    public function test_component_validates_log_limit(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
            ]);

        // Test invalid log limit (too small)
        $component->set('logLimit', 2)
            ->call('updateLogLimit')
            ->assertHasErrors(['logLimit']);

        // Test invalid log limit (too large)
        $component->set('logLimit', 150)
            ->call('updateLogLimit')
            ->assertHasErrors(['logLimit']);

        // Test valid log limit
        $component->set('logLimit', 50)
            ->call('updateLogLimit')
            ->assertHasNoErrors();
    }

    public function test_component_filters_logs_by_level(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $logEntries = [
            '[2024-01-01 10:00:00] local.INFO: Info log {"processing_id":"'.$processingId.'","step":"initialization"}',
            '[2024-01-01 10:00:05] local.ERROR: Error log {"processing_id":"'.$processingId.'","step":"transcription"}',
            '[2024-01-01 10:00:10] local.WARNING: Warning log {"processing_id":"'.$processingId.'","step":"analysis"}',
        ];

        File::put($this->originalLogFile, implode("\n", $logEntries));

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
            ]);

        // Initially, all logs should be visible
        $component->assertCount('logs', 3);

        // Filter by error level
        $component->set('filterLevel', 'error')
            ->call('updateFilter');

        $filteredLogs = $component->get('filteredLogs');
        $this->assertCount(1, $filteredLogs);
        $this->assertEquals('error', $filteredLogs->first()['level']);

        // Filter by warning level
        $component->set('filterLevel', 'warning')
            ->call('updateFilter');

        $filteredLogs = $component->get('filteredLogs');
        $this->assertCount(1, $filteredLogs);
        $this->assertEquals('warning', $filteredLogs->first()['level']);

        // Reset filter
        $component->set('filterLevel', 'all')
            ->call('updateFilter');

        $filteredLogs = $component->get('filteredLogs');
        $this->assertCount(3, $filteredLogs);
    }

    public function test_component_filters_logs_by_step(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $logEntries = [
            '[2024-01-01 10:00:00] local.INFO: Log 1 {"processing_id":"'.$processingId.'","step":"initialization"}',
            '[2024-01-01 10:00:05] local.INFO: Log 2 {"processing_id":"'.$processingId.'","step":"transcription"}',
            '[2024-01-01 10:00:10] local.INFO: Log 3 {"processing_id":"'.$processingId.'","step":"initialization"}',
        ];

        File::put($this->originalLogFile, implode("\n", $logEntries));

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
            ]);

        // Check available steps
        $availableSteps = $component->get('availableSteps');
        $this->assertContains('initialization', $availableSteps);
        $this->assertContains('transcription', $availableSteps);

        // Filter by initialization step
        $component->set('filterStep', 'initialization')
            ->call('updateFilter');

        $filteredLogs = $component->get('filteredLogs');

        // Filter to only our test logs to avoid interference from other log entries during test
        $testInitializationLogs = $filteredLogs->filter(function ($log) {
            return $log['step'] === 'initialization' &&
                   isset($log['message']) &&
                   preg_match('/Log [13]/', $log['message']); // Our test logs are "Log 1" and "Log 3"
        });

        $this->assertCount(2, $testInitializationLogs, 'Should find exactly 2 test initialization logs');
        $this->assertTrue($filteredLogs->every(fn ($log) => $log['step'] === 'initialization'));

        // Filter by transcription step
        $component->set('filterStep', 'transcription')
            ->call('updateFilter');

        $filteredLogs = $component->get('filteredLogs');
        $this->assertCount(1, $filteredLogs);
        $this->assertEquals('transcription', $filteredLogs->first()['step']);
    }

    public function test_component_computes_processing_time_correctly(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
            ]);

        // Set performance metrics
        $component->set('performanceMetrics', [
            'total_execution_time' => 125.5, // 2 minutes, 5.5 seconds
        ]);

        $this->assertEquals('2m 6s', $component->get('processingTime'));

        // Test seconds only
        $component->set('performanceMetrics', [
            'total_execution_time' => 45.3,
        ]);

        $this->assertEquals('45.3s', $component->get('processingTime'));

        // Test no metrics
        $component->set('performanceMetrics', []);
        $this->assertEquals('N/A', $component->get('processingTime'));
    }

    public function test_component_computes_memory_peak_correctly(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
            ]);

        // Set performance metrics
        $component->set('performanceMetrics', [
            'peak_memory_usage' => 2097152, // 2 MB
        ]);

        $this->assertEquals('2 MB', $component->get('memoryPeak'));

        // Test no metrics
        $component->set('performanceMetrics', []);
        $this->assertEquals('N/A', $component->get('memoryPeak'));
    }

    public function test_component_computes_status_color_correctly(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
            ]);

        // Test different statuses
        $component->set('statusData', ['status' => 'completed']);
        $this->assertEquals('green', $component->get('statusColor'));

        $component->set('statusData', ['status' => 'processing']);
        $this->assertEquals('blue', $component->get('statusColor'));

        $component->set('statusData', ['status' => 'failed']);
        $this->assertEquals('red', $component->get('statusColor'));

        $component->set('statusData', ['status' => 'cancelled']);
        $this->assertEquals('gray', $component->get('statusColor'));

        $component->set('statusData', ['status' => 'unknown']);
        $this->assertEquals('gray', $component->get('statusColor'));
    }

    public function test_component_handles_missing_processing_id_gracefully(): void
    {
        $nonExistentId = Str::uuid()->toString();

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $nonExistentId,
            ]);

        // Should not throw errors
        $component->assertStatus(200)
            ->assertSet('processingId', $nonExistentId)
            ->assertCount('logs', 0)
            ->assertCount('statusData', 0);
    }

    public function test_component_displays_no_logs_message_when_empty(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
                'expanded' => true,
            ]);

        $component->assertSee('No processing logs available');
    }

    public function test_component_respects_custom_initialization_parameters(): void
    {
        $processingId = Str::uuid()->toString();

        MediaProcessingLog::create([
            'processing_id' => $processingId,
            'source_type' => 'audio',
            'status' => ProcessingStatus::PROCESSING,
            'original_filename' => 'test.mp3',
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(ProcessingLogsViewer::class, [
                'processingId' => $processingId,
                'autoRefresh' => false,
                'expanded' => true,
                'logLimit' => 50,
            ]);

        $component->assertSet('processingId', $processingId)
            ->assertSet('autoRefresh', false)
            ->assertSet('expanded', true)
            ->assertSet('logLimit', 50);
    }
}
