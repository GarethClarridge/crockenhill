<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ProcessingStatus;
use App\Jobs\DetectServiceStructure;
use App\Mail\ManualReviewRequired;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\Structure\MockServiceStructureService;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\Sermon\SermonCandidateConfidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetectServiceStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.service_structure.detector', 'mock');
    }

    protected function tearDown(): void
    {
        MockServiceStructureService::useStructure(null);

        parent::tearDown();
    }

    #[Test]
    public function off_mode_does_nothing(): void
    {
        Config::set('media-processing.service_structure.mode', 'off');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $this->runJob($log);

        $log->refresh();
        $this->assertArrayNotHasKey('service_structure_shadow', $log->processing_metadata?->toArray() ?? []);
        $this->assertSame(0, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
    }

    #[Test]
    public function shadow_mode_records_the_proposal_without_touching_heuristic_sections(): void
    {
        Config::set('media-processing.service_structure.mode', 'shadow');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        MockServiceStructureService::useStructure($this->validStructure());

        $heuristicSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => 'sermon',
            'section_order' => 1,
            'start_time' => 610.0,
            'end_time' => 2190.0,
            'status' => 'identified',
        ]);
        $heuristicSnapshot = $heuristicSection->fresh()->toArray();
        $segmentCountBefore = LivestreamSegment::query()->count();

        $this->runJob($log);

        // The heuristic path stays authoritative: sections untouched, no
        // synthesised segments, run still healthy.
        $this->assertSame($heuristicSnapshot, $heuristicSection->fresh()->toArray());
        $this->assertSame($segmentCountBefore, LivestreamSegment::query()->count());
        $this->assertSame(1, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());

        $shadow = $log->refresh()->processing_metadata?->toArray()['service_structure_shadow'] ?? null;
        $this->assertIsArray($shadow);
        $this->assertTrue($shadow['passed_validation']);
        $this->assertCount(4, $shadow['sections']);
        $this->assertIsArray($shadow['diff']);
        $this->assertSame(1, $shadow['diff']['heuristic_section_count']);
        $this->assertSame(4, $shadow['diff']['llm_section_count']);
        $this->assertEqualsWithDelta(-10.0, $shadow['diff']['sermon']['start_delta'], 0.01);
        $this->assertEqualsWithDelta(10.0, $shadow['diff']['sermon']['end_delta'], 0.01);
    }

    #[Test]
    public function shadow_mode_swallows_failures_and_records_the_error(): void
    {
        Config::set('media-processing.service_structure.mode', 'shadow');

        // No transcript artifact stored — detection cannot run.
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $this->runJob($log);

        $log->refresh();
        $shadow = $log->processing_metadata?->toArray()['service_structure_shadow'] ?? null;
        $this->assertIsArray($shadow);
        $this->assertStringContainsString('transcript', $shadow['error']);
        $this->assertNotSame(ProcessingStatus::Failed, $log->status, 'A shadow failure never fails the run.');
    }

    #[Test]
    public function primary_mode_persists_validated_sections(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        MockServiceStructureService::useStructure($this->validStructure());

        $this->runJob($log);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $this->assertSame(
            ['welcome', 'bible_reading', 'sermon', 'song'],
            $sections->pluck('section_type')->map(fn ($type) => $type->value)->all()
        );
        $this->assertSame('llm_structure', $sections[0]->metadata['classification_mode']);
        $this->assertNotSame(ProcessingStatus::Failed, $log->refresh()->status);

        // The accepted sermon section's bounds replace the RMS guess on the
        // run itself, so baseline extraction and external submission agree
        // with the validated structure.
        $this->assertEqualsWithDelta(600.0, (float) $log->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(2200.0, (float) $log->sermon_end_time, 0.01);
        $this->assertSame('llm_structure', $log->processing_metadata?->toArray()['sermon_bounds']['source'] ?? null);
    }

    #[Test]
    public function primary_mode_never_moves_manually_confirmed_sermon_bounds(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 100.0,
            'sermon_end_time' => 900.0,
            'processing_metadata' => [
                'manual_review' => [
                    'status' => 'confirmed',
                    'confirmed_segment_id' => 42,
                ],
            ],
        ]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        MockServiceStructureService::useStructure($this->validStructure());

        $this->runJob($log);

        $log->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $log->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(900.0, (float) $log->sermon_end_time, 0.01);
        $this->assertArrayNotHasKey('sermon_bounds', $log->processing_metadata?->toArray() ?? []);
    }

    #[Test]
    public function primary_mode_does_not_promote_a_review_flagged_sermon_into_the_baseline(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 1800.0,
        ]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // A below-threshold sermon gets a soft structure_low_confidence flag; the
        // resolver's findPreferredSection() excludes such sections from
        // automatic extraction, so its bounds must not overwrite the RMS baseline.
        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2200.0, confidence: 0.5),
            $this->section('song', 2210.0, 2400.0),
        ], ['Fixture structure.'], 'mock'));

        $this->runJob($log);

        $log->refresh();
        // Structure still persisted (soft flag, not a hard failure)...
        $this->assertNotSame(ProcessingStatus::Failed, $log->status);
        // ...but the run bounds keep the RMS baseline and no write-back is recorded.
        $this->assertEqualsWithDelta(300.0, (float) $log->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(1800.0, (float) $log->sermon_end_time, 0.01);
        $this->assertArrayNotHasKey('sermon_bounds', $log->processing_metadata?->toArray() ?? []);
    }

    #[Test]
    public function primary_mode_routes_hard_validation_failures_to_manual_review(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.email.admin_email', 'admin@example.com');
        Mail::fake();

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // Two sermons — a hard validator failure.
        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 1000.0),
            $this->section('sermon', 1100.0, 2300.0),
        ], model: 'mock'));

        $job = new DetectServiceStructure($log);
        $job->handle(
            app(ServiceStructureInterface::class),
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );

        $log->refresh();
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertSame('manual_review_required', $log->current_step);
        $this->assertStringContainsString('sermon', (string) $log->error_message);
        $this->assertSame(0, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
        $this->assertSame([], $job->chained, 'The remaining chained jobs are cancelled.');

        // The rejected proposal survives for the reviewer and for scoring —
        // without creating sections or synthesising segments.
        $proposal = $log->processing_metadata?->toArray()['service_structure_proposal'] ?? null;
        $this->assertIsArray($proposal);
        $this->assertFalse($proposal['passed_validation']);
        $this->assertContains('multiple_sermons', array_column($proposal['hard_failures'], 'code'));
        $this->assertCount(2, $proposal['sections']);
        $this->assertSame('sermon', $proposal['sections'][0]['section_type']);
        $this->assertArrayNotHasKey('transcript', $proposal['sections'][0]['metadata']);
        $this->assertSame(3, LivestreamSegment::query()->where('media_processing_log_id', $log->id)->count());

        Mail::assertQueued(
            ManualReviewRequired::class,
            fn (ManualReviewRequired $mail): bool => $mail->processingId === $log->processing_id
                && $mail->hasTo('admin@example.com')
        );
    }

    private function runJob(MediaProcessingLog $log): void
    {
        (new DetectServiceStructure($log))->handle(
            app(ServiceStructureInterface::class),
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );
    }

    private function storeTranscript(MediaProcessingLog $log): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 120.0, 'text' => 'Good morning everyone and a very warm welcome.'],
            ['start' => 420.0, 'end' => 590.0, 'text' => 'Our reading is from Joshua chapter one.'],
            ['start' => 600.0, 'end' => 2200.0, 'text' => 'Please turn with me to our passage.'],
            ['start' => 2210.0, 'end' => 2400.0, 'text' => 'Praise my soul the King of heaven.'],
        ], 2430.0, ChurchServiceTranscript::SOURCE_MOCK);

        $path = 'temp/service_transcript_'.$log->processing_id.'.json';
        Storage::disk('local')->put($path, (string) json_encode($transcript));
        $log->putServiceTranscriptPath($path);
    }

    private function coveringSegments(MediaProcessingLog $log): void
    {
        foreach ([[0.0, 430.0], [430.0, 1500.0], [1500.0, 2430.0]] as $index => [$start, $end]) {
            LivestreamSegment::factory()->create([
                'media_processing_log_id' => $log->id,
                'segment_index' => $index,
                'segment_order' => $index,
                'start_time' => $start,
                'end_time' => $end,
                'duration' => $end - $start,
                'classification' => 'speech',
            ]);
        }
    }

    private function validStructure(): ServiceStructure
    {
        return ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2200.0),
            $this->section('song', 2210.0, 2400.0),
        ], ['Fixture structure.'], 'mock');
    }

    private function section(string $type, float $start, float $end, float $confidence = 0.95): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => $confidence,
        ]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }
}
