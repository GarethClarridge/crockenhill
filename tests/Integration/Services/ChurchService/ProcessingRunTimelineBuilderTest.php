<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Services\ChurchService\ProcessingRunTimelineBuilder;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessingRunTimelineBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_timelines_for_all_runs_in_a_collection(): void
    {
        $run1 = MediaProcessingLog::factory()->create();
        $run2 = MediaProcessingLog::factory()->create();

        $results = ProcessingRunTimelineBuilder::buildAll(new EloquentCollection([$run1, $run2]));

        $this->assertCount(2, $results);
        $this->assertArrayHasKey($run1->id, $results);
        $this->assertArrayHasKey($run2->id, $results);
        $this->assertIsArray($results[$run1->id]);
        $this->assertIsArray($results[$run2->id]);
    }

    #[Test]
    public function it_marks_all_steps_as_pending_for_a_fresh_run(): void
    {
        $run = MediaProcessingLog::factory()->processing()->create([
            'current_step' => 'awaiting_processing',
        ]);

        $timeline = ProcessingRunTimelineBuilder::buildForRun($run);

        $this->assertCount(count(ChurchServiceProcessingTimeline::steps()), $timeline);

        foreach ($timeline as $entry) {
            $this->assertSame('pending', $entry['status']);
            $this->assertNull($entry['started_at']);
        }
    }

    #[Test]
    public function it_marks_current_step_as_running_for_in_progress_run(): void
    {
        $run = MediaProcessingLog::factory()->processing()->create([
            'current_step' => 'extracting_sermon',
        ]);

        $timeline = ProcessingRunTimelineBuilder::buildForRun($run);

        // Find the 'extract_sermon' step in the timeline
        $extractStep = collect($timeline)->firstWhere('label', 'Extract sermon');

        $this->assertNotNull($extractStep);
        $this->assertSame('running', $extractStep['status']);

        // Steps after should be pending.
        $prepareStep = collect($timeline)->firstWhere('label', 'Prepare publication candidates');
        $this->assertSame('pending', $prepareStep['status']);
    }

    #[Test]
    public function it_uses_recorded_step_data_when_available(): void
    {
        $run = MediaProcessingLog::factory()->processing()->create([
            'current_step' => 'extracting_sermon',
        ]);

        $startedAt = now()->subMinutes(5);
        $completedAt = now()->subMinutes(2);

        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS,
            'status' => ProcessingStatus::Completed,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'message' => 'Classification finished.',
        ]);

        $timeline = ProcessingRunTimelineBuilder::buildForRun($run);

        $classifyStep = collect($timeline)->firstWhere('label', 'Classify service sections');

        $this->assertSame('completed', $classifyStep['status']);
        $this->assertEquals($startedAt->toDateTimeString(), $classifyStep['started_at']->toDateTimeString());
        $this->assertEquals($completedAt->toDateTimeString(), $classifyStep['completed_at']->toDateTimeString());
        $this->assertSame('3m 00s', $classifyStep['duration']);
        $this->assertSame('Classification finished.', $classifyStep['message']);
    }

    #[Test]
    public function it_marks_step_as_failed_when_run_fails_at_that_step(): void
    {
        $run = MediaProcessingLog::factory()->failed()->create([
            'current_step' => 'extracting_sermon',
            'error_message' => 'Extraction failed due to timeout.',
        ]);

        $timeline = ProcessingRunTimelineBuilder::buildForRun($run);

        $extractStep = collect($timeline)->firstWhere('label', 'Extract sermon');

        $this->assertNotNull($extractStep);
        $this->assertSame('failed', $extractStep['status']);
        $this->assertSame('Extraction failed due to timeout.', $extractStep['message']);
    }

    #[Test]
    public function it_marks_step_as_skipped_when_run_is_cancelled_at_that_step(): void
    {
        $run = MediaProcessingLog::factory()->cancelled()->create([
            'current_step' => 'extracting_sermon',
            'error_message' => 'User cancelled.',
        ]);

        $timeline = ProcessingRunTimelineBuilder::buildForRun($run);

        $extractStep = collect($timeline)->firstWhere('label', 'Extract sermon');

        $this->assertNotNull($extractStep);
        $this->assertSame('skipped', $extractStep['status']);
        $this->assertSame('User cancelled.', $extractStep['message']);
    }

    #[Test]
    public function it_marks_missing_historical_steps_as_not_recorded_for_completed_run(): void
    {
        $run = MediaProcessingLog::factory()->completed()->create([
            'current_step' => 'extraction_complete',
        ]);

        // No step logs created

        $timeline = ProcessingRunTimelineBuilder::buildForRun($run);

        foreach ($timeline as $entry) {
            $this->assertSame('not_recorded', $entry['status']);
            $this->assertSame('No step log recorded for this older run.', $entry['message']);
        }
    }

    #[Test]
    public function it_marks_missing_preceding_steps_as_not_recorded_if_a_later_step_has_logs(): void
    {
        $run = MediaProcessingLog::factory()->processing()->create([
            'current_step' => 'projecting_service_structure',
        ]);

        // Record a log for the 4th step (Project service structure)
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE,
            'status' => ProcessingStatus::Completed,
        ]);

        $timeline = ProcessingRunTimelineBuilder::buildForRun($run);

        // First 3 steps should be 'not_recorded' because step 4 has logs
        $this->assertSame('not_recorded', $timeline[0]['status']);
        $this->assertSame('not_recorded', $timeline[1]['status']);
        $this->assertSame('not_recorded', $timeline[2]['status']);

        // 4th step is completed (from log)
        $this->assertSame('completed', $timeline[3]['status']);
    }

    #[Test]
    public function it_formats_durations_correctly(): void
    {
        $run = MediaProcessingLog::factory()->create();

        // 0 seconds
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // 45 seconds
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS,
            'started_at' => now()->subSeconds(45),
            'completed_at' => now(),
        ]);

        // 2m 15s
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::CLASSIFY_SPEECH_SECTIONS,
            'started_at' => now()->subSeconds(135),
            'completed_at' => now(),
        ]);

        // 1h 5m 10s
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::PROJECT_LIVESTREAM_SERVICE_STRUCTURE,
            'started_at' => now()->subSeconds(3600 + 300 + 10),
            'completed_at' => now(),
        ]);

        $timeline = ProcessingRunTimelineBuilder::buildForRun($run);

        $this->assertSame('0s', collect($timeline)->firstWhere('label', 'Classify service sections')['duration']);
        $this->assertSame('45s', collect($timeline)->firstWhere('label', 'Transcribe speech segments')['duration']);
        $this->assertSame('2m 15s', collect($timeline)->firstWhere('label', 'Classify speech sections')['duration']);
        $this->assertSame('1h 05m 10s', collect($timeline)->firstWhere('label', 'Project service structure')['duration']);
    }

    #[Test]
    public function it_normalises_messages(): void
    {
        $run = MediaProcessingLog::factory()->create();

        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS,
            'status' => ProcessingStatus::Started,
            'message' => '  Trimmed message  ',
        ]);

        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS,
            'status' => ProcessingStatus::Started,
            'message' => '   ',
        ]);

        $timeline = ProcessingRunTimelineBuilder::buildForRun($run);

        $this->assertSame('Trimmed message', collect($timeline)->firstWhere('label', 'Classify service sections')['message']);
        $this->assertNull(collect($timeline)->firstWhere('label', 'Transcribe speech segments')['message']);
    }

    #[Test]
    public function timeline_steps_follow_the_service_structure_mode(): void
    {
        config(['media-processing.service_structure.mode' => 'off']);
        $this->assertNotContains(
            ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE,
            ChurchServiceProcessingTimeline::stepKeys()
        );

        config(['media-processing.service_structure.mode' => 'shadow']);
        $shadowKeys = ChurchServiceProcessingTimeline::stepKeys();
        $this->assertContains(ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE, $shadowKeys);
        $this->assertContains(ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE, $shadowKeys);
        // Shadow keeps the heuristic cluster and appends the LLM steps after it.
        $this->assertContains(ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS, $shadowKeys);
        $this->assertGreaterThan(
            array_search(ChurchServiceProcessingTimeline::RECLASSIFY_INTRO_OUTRO, $shadowKeys, true),
            array_search(ChurchServiceProcessingTimeline::TRANSCRIBE_FULL_SERVICE, $shadowKeys, true)
        );

        config(['media-processing.service_structure.mode' => 'primary']);
        $primaryKeys = ChurchServiceProcessingTimeline::stepKeys();
        $this->assertContains(ChurchServiceProcessingTimeline::DETECT_SERVICE_STRUCTURE, $primaryKeys);
        // Primary drops the heuristic-cluster steps that will never log.
        $this->assertNotContains(ChurchServiceProcessingTimeline::CLASSIFY_SERVICE_SECTIONS, $primaryKeys);
        $this->assertNotContains(ChurchServiceProcessingTimeline::ALIGN_WITH_OOS, $primaryKeys);
        $this->assertContains(ChurchServiceProcessingTimeline::EXTRACT_SERMON, $primaryKeys);
    }

    #[Test]
    public function the_full_service_transcription_step_shows_as_running_in_primary_mode(): void
    {
        config(['media-processing.service_structure.mode' => 'primary']);

        $run = MediaProcessingLog::factory()->processing()->create([
            'current_step' => 'transcribe_full_service',
        ]);

        $timeline = ProcessingRunTimelineBuilder::buildForRun($run);

        $this->assertSame(
            'running',
            collect($timeline)->firstWhere('label', 'Transcribe full service')['status']
        );
    }
}
