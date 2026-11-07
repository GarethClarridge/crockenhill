<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReviewInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/review-inventory-'.uniqid();
        File::ensureDirectoryExists($this->directory.'/replay');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function it_writes_the_inventory_summary_and_routes_from_a_replay(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create(['status' => ProcessingStatus::Completed]);
        $recut = ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'section_type' => ServiceSectionType::Sermon,
            'start_time' => 100, 'end_time' => 900, 'needs_manual_review' => true, 'metadata' => ['review_flags' => ['sermon_text_predates_evidence']]]);
        $talk = ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'section_type' => ServiceSectionType::ShortTalk,
            'start_time' => 1000, 'end_time' => 1200, 'metadata' => ['talk_type' => ['proposed' => 'testimony']]]);
        ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'section_type' => ServiceSectionType::BibleReading,
            'start_time' => 1300, 'end_time' => 1400, 'publication_status' => ServiceSectionPublicationStatus::PendingApproval]);
        $stopped = MediaProcessingLog::factory()->livestream()->create(['status' => ProcessingStatus::Failed]);
        file_put_contents($this->directory.'/replay/rows-0.jsonl', json_encode(['run' => $run->id, 'sermon' => [
            'recorded_segments' => [['start_time' => 100.0, 'end_time' => 900.0]], 'replayed_segments' => [['start_time' => 95.0, 'end_time' => 900.0]],
            'same_cut' => false, 'recorded_selected' => [$recut->id], 'replayed_selected' => [$recut->id],
        ]])."\n");

        $this->artisan('historic-import:review-inventory', ['--replay' => $this->directory.'/replay', '--output' => $this->directory.'/out'])
            ->assertSuccessful();

        $records = array_map(fn (string $line): array => json_decode($line, true), file($this->directory.'/out/inventory.jsonl', FILE_IGNORE_NEW_LINES));
        $summary = json_decode((string) file_get_contents($this->directory.'/out/summary.json'), true);
        $routes = json_decode((string) file_get_contents($this->directory.'/out/routes.json'), true);

        $this->assertCount(3, $records);
        $this->assertSame('resolved_by_pending_application', collect($records)->firstWhere('section_id', $recut->id)['disposition']);
        $this->assertSame('human_decision', collect($records)->firstWhere('section_id', $talk->id)['disposition']);
        // The pending-approval-only reading is not processing review; the sermon clears; the talk keeps its decision.
        $this->assertSame([3, 2, 1, 1], [$summary['sections'], $summary['processing_review_sections'], $summary['removable_sections'], $summary['human_sections']]);
        $this->assertSame([$stopped->id], array_column($summary['stopped_runs'], 'id'));
        $this->assertSame([(string) $run->id => ['re-extract sermon (cut changes)']], $routes);
    }

    #[Test]
    public function a_stopped_run_is_routed_through_its_own_failure_first(): void
    {
        $stopped = MediaProcessingLog::factory()->livestream()->create(['status' => ProcessingStatus::Failed, 'current_step' => 'segmentation']);
        file_put_contents($this->directory.'/replay/rows-0.jsonl', json_encode(['run' => $stopped->id,
            'structure' => ['diffs' => [['stored' => ['start_time' => 0.0, 'end_time' => 10.0], 'replayed' => ['start_time' => 0.0, 'end_time' => 12.0]]]]])."\n");

        $this->artisan('historic-import:review-inventory', ['--replay' => $this->directory.'/replay', '--output' => $this->directory.'/out'])
            ->assertSuccessful();

        $routes = json_decode((string) file_get_contents($this->directory.'/out/routes.json'), true);
        $this->assertSame(['stopped run (failed at segmentation): route its failure first', 'recompose (structure changes)'], $routes[(string) $stopped->id]);
    }

    #[Test]
    public function it_refuses_without_an_output_directory_or_with_an_empty_replay(): void
    {
        $this->artisan('historic-import:review-inventory')->assertFailed();
        $this->artisan('historic-import:review-inventory', ['--replay' => $this->directory.'/replay', '--output' => $this->directory.'/out'])
            ->expectsOutputToContain('No replay rows')
            ->assertFailed();
    }

    #[Test]
    public function without_a_replay_nothing_counts_as_pending_application(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create(['status' => ProcessingStatus::Completed]);
        ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'section_type' => ServiceSectionType::Sermon,
            'needs_manual_review' => true, 'metadata' => ['review_flags' => ['sermon_text_predates_evidence']]]);

        $this->artisan('historic-import:review-inventory', ['--output' => $this->directory.'/out'])
            ->expectsOutputToContain('No --replay')
            ->assertSuccessful();

        $record = json_decode(trim((string) file_get_contents($this->directory.'/out/inventory.jsonl')), true);
        $this->assertSame('automatic_repair', $record['disposition']);
    }
}
