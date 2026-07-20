<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ProcessingStatus;
use App\Jobs\AnalyzeSegments;
use App\Jobs\DetectServiceStructure;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\TranscribeFullService;
use App\Jobs\ValidateVideoFile;
use App\Mail\ManualReviewRequired;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\Structure\MockServiceStructureService;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\Processing\ProcessingPipelineBuilder;
use App\Services\Sermon\SermonCandidateConfidenceService;
use App\Services\Sermon\SermonExtractionPlanResolver;
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
    public function shadow_mode_compares_the_candidate_with_stored_authoritative_sections(): void
    {
        Config::set('media-processing.service_structure.mode', 'shadow');
        Config::set('media-processing.service_structure.model', 'gpt-5');
        Config::set('media-processing.service_structure.shadow_model', 'gpt-6-candidate');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);

        $boundStructure = $this->validStructure();
        $candidateStructure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 620.0, 2180.0),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'gpt-6-candidate');

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => 'sermon',
            'section_order' => 1,
            'start_time' => 100.0,
            'end_time' => 200.0,
            'status' => 'identified',
            'metadata' => ['classification_mode' => 'llm_structure', 'model' => 'gpt-5'],
        ]);

        $detector = new class($boundStructure, $candidateStructure) implements ServiceStructureInterface
        {
            /** @var list<string> */
            public array $modelsAtDetectTime = [];

            public function __construct(
                private readonly ServiceStructure $boundStructure,
                private readonly ServiceStructure $candidateStructure,
            ) {}

            public function detect(ChurchServiceTranscript $transcript, array $oosItems, ?string $processingId = null, array $feedback = []): ServiceStructure
            {
                $model = (string) config('media-processing.service_structure.model');
                $this->modelsAtDetectTime[] = $model;

                return $model === 'gpt-6-candidate' ? $this->candidateStructure : $this->boundStructure;
            }
        };

        (new DetectServiceStructure($log))->handle(
            $detector,
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );

        $this->assertSame(['gpt-6-candidate'], $detector->modelsAtDetectTime);
        $this->assertSame('gpt-5', config('media-processing.service_structure.model'), 'The bound model must be restored after the shadow run.');

        $shadow = $log->refresh()->processing_metadata?->toArray()['service_structure_shadow'] ?? null;
        $this->assertIsArray($shadow);
        $this->assertSame(['gpt-5'], $shadow['diff']['baseline']['models'] ?? null);
        $this->assertEqualsWithDelta(520.0, $shadow['diff']['sermon']['start_delta'], 0.01);
        $this->assertEqualsWithDelta(1980.0, $shadow['diff']['sermon']['end_delta'], 0.01);
    }

    #[Test]
    public function shadow_mode_runs_the_bound_model_when_authoritative_sections_are_missing(): void
    {
        Config::set('media-processing.service_structure.mode', 'shadow');
        Config::set('media-processing.service_structure.model', 'gpt-5');
        Config::set('media-processing.service_structure.shadow_model', 'gpt-6-candidate');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);

        $invalidBoundStructure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('sermon', 600.0, 41410.0),
        ], model: 'gpt-5');
        $recoveredBoundStructure = $this->validStructure();
        $candidateStructure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 620.0, 2180.0),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'gpt-6-candidate');

        $detector = new class($invalidBoundStructure, $recoveredBoundStructure, $candidateStructure) implements ServiceStructureInterface
        {
            /** @var list<string> */
            public array $modelsAtDetectTime = [];

            private int $boundAttempts = 0;

            public function __construct(
                private readonly ServiceStructure $invalidBoundStructure,
                private readonly ServiceStructure $recoveredBoundStructure,
                private readonly ServiceStructure $candidateStructure,
            ) {}

            public function detect(ChurchServiceTranscript $transcript, array $oosItems, ?string $processingId = null, array $feedback = []): ServiceStructure
            {
                $model = (string) config('media-processing.service_structure.model');
                $this->modelsAtDetectTime[] = $model;

                if ($model === 'gpt-6-candidate') {
                    return $this->candidateStructure;
                }

                return $this->boundAttempts++ === 0
                    ? $this->invalidBoundStructure
                    : $this->recoveredBoundStructure;
            }
        };

        (new DetectServiceStructure($log))->handle(
            $detector,
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );

        $this->assertSame(['gpt-5', 'gpt-5', 'gpt-6-candidate'], $detector->modelsAtDetectTime);
        $this->assertSame('gpt-5', config('media-processing.service_structure.model'));

        $shadow = $log->refresh()->processing_metadata?->toArray()['service_structure_shadow'] ?? null;
        $this->assertIsArray($shadow);
        $this->assertSame(['gpt-5'], $shadow['diff']['baseline']['models'] ?? null);
        $this->assertEqualsWithDelta(20.0, $shadow['diff']['sermon']['start_delta'], 0.01);
        $this->assertEqualsWithDelta(-20.0, $shadow['diff']['sermon']['end_delta'], 0.01);
    }

    #[Test]
    public function shadow_mode_does_not_score_a_candidate_against_an_invalid_bound_model_baseline(): void
    {
        Config::set('media-processing.service_structure.mode', 'shadow');
        Config::set('media-processing.service_structure.model', 'gpt-5');
        Config::set('media-processing.service_structure.shadow_model', 'gpt-6-candidate');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);

        $invalidBoundStructure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('sermon', 600.0, 41410.0),
        ], model: 'gpt-5');

        $detector = new class($invalidBoundStructure, $this->validStructure()) implements ServiceStructureInterface
        {
            /** @var list<string> */
            public array $modelsAtDetectTime = [];

            public function __construct(
                private readonly ServiceStructure $boundStructure,
                private readonly ServiceStructure $candidateStructure,
            ) {}

            public function detect(ChurchServiceTranscript $transcript, array $oosItems, ?string $processingId = null, array $feedback = []): ServiceStructure
            {
                $model = (string) config('media-processing.service_structure.model');
                $this->modelsAtDetectTime[] = $model;

                return $model === 'gpt-6-candidate' ? $this->candidateStructure : $this->boundStructure;
            }
        };

        (new DetectServiceStructure($log))->handle(
            $detector,
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );

        $this->assertSame(['gpt-5', 'gpt-5'], $detector->modelsAtDetectTime);
        $this->assertSame('gpt-5', config('media-processing.service_structure.model'));

        $shadow = $log->refresh()->processing_metadata?->toArray()['service_structure_shadow'] ?? null;
        $this->assertIsArray($shadow);
        $this->assertStringContainsString('did not produce a valid shadow baseline', $shadow['error']);
        $this->assertArrayNotHasKey('diff', $shadow);
    }

    #[Test]
    public function the_shadow_diff_records_baseline_provenance(): void
    {
        Config::set('media-processing.service_structure.mode', 'shadow');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        MockServiceStructureService::useStructure($this->validStructure());

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => 'sermon',
            'section_order' => 1,
            'start_time' => 610.0,
            'end_time' => 2190.0,
            'status' => 'identified',
            'metadata' => ['classification_mode' => 'audio_only', 'model' => 'heuristic-v2'],
        ]);

        $this->runJob($log);

        $shadow = $log->refresh()->processing_metadata?->toArray()['service_structure_shadow'] ?? null;
        $this->assertIsArray($shadow);
        $this->assertSame(
            ['audio_only'],
            $shadow['diff']['baseline']['classification_modes'] ?? null,
            'The diff must record who authored the baseline it compared against.'
        );
        $this->assertSame(['heuristic-v2'], $shadow['diff']['baseline']['models'] ?? null);
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
    public function auto_trim_primary_mode_uses_the_llm_sequence_and_produces_plausible_sermon_boundaries(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'duration' => 2430.0,
            'sermon_start_time' => 500.0,
            'sermon_end_time' => 2100.0,
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'trim_requested' => true,
            ],
        ]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        MockServiceStructureService::useStructure($this->validStructure());

        $pipeline = app(ProcessingPipelineBuilder::class)->buildAutoTrimVideoPipeline($log);

        $this->assertSame([
            ValidateVideoFile::class,
            GenerateRmsLog::class,
            AnalyzeSegments::class,
            TranscribeFullService::class,
            DetectServiceStructure::class,
            ExtractSermon::class,
        ], array_map(
            static fn (object $job): string => $job::class,
            array_slice($pipeline, 0, 6),
        ));

        $this->runJob($log);

        $sermonSection = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'sermon')
            ->firstOrFail();
        $extractionPlan = app(SermonExtractionPlanResolver::class)->resolve($log->refresh());

        $this->assertEqualsWithDelta(600.0, (float) $sermonSection->start_time, 0.01);
        $this->assertEqualsWithDelta(2200.0, (float) $sermonSection->end_time, 0.01);
        $this->assertSame('service_sections', $extractionPlan['source']);
        $this->assertEqualsWithDelta(420.0, $extractionPlan['segments'][0]['start_time'], 0.01);
        $this->assertEqualsWithDelta(2200.0, $extractionPlan['segments'][0]['end_time'], 0.01);
        $this->assertSame('llm_structure', $log->processing_metadata?->toArray()['sermon_bounds']['source'] ?? null);
    }

    #[Test]
    public function auto_trim_primary_mode_keeps_the_rms_baseline_when_the_sermon_needs_review(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->video()->pending()->create([
            'duration' => 2430.0,
            'sermon_start_time' => 500.0,
            'sermon_end_time' => 2100.0,
            'processing_metadata' => [
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            ],
        ]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2200.0, confidence: 0.5),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'mock'));

        $this->runJob($log);

        $extractionPlan = app(SermonExtractionPlanResolver::class)->resolve($log->refresh());

        $this->assertEqualsWithDelta(500.0, (float) $log->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(2100.0, (float) $log->sermon_end_time, 0.01);
        $this->assertSame('processing_log', $extractionPlan['source']);
        $this->assertSame('no_high_confidence_sermon_section', $extractionPlan['metadata']['reason']);
        $this->assertArrayNotHasKey('sermon_bounds', $log->processing_metadata?->toArray() ?? []);
    }

    #[Test]
    public function primary_mode_still_skips_direct_video_runs(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->video()->pending()->create();

        $this->runJob($log);

        $this->assertSame(0, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
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
    public function primary_mode_promotes_bounds_for_a_sermon_flagged_only_with_a_cross_type_inversion(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $churchService = ChurchService::factory()->create();
        $songItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'title' => 'Praise My Soul',
            'position' => 2,
        ]);
        $sermonItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'custom',
            'title' => 'Sermon',
            'position' => 1,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'church_service_id' => $churchService->id,
            'sermon_start_time' => 300.0,
            'sermon_end_time' => 1800.0,
        ]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // The song claims OoS position 2 before the sermon claims position 1 —
        // a cross-type inversion (OpenLP groups items by type, so this is a
        // legitimate authoring style). The soft flag lands on the sermon but
        // must not demote the run to the RMS baseline: an ordering concern
        // says nothing about the sermon's boundaries.
        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('song', 130.0, 400.0, oosItemId: (int) $songItem->id),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2200.0, oosItemId: (int) $sermonItem->id),
            $this->section('song', 2210.0, 2400.0),
        ], ['Fixture structure.'], 'mock'));

        $this->runJob($log);

        $log->refresh();
        $this->assertNotSame(ProcessingStatus::Failed, $log->status);

        $sermonSection = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'sermon')
            ->firstOrFail();
        $this->assertSame(
            [ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION],
            $sermonSection->metadata['review_flags']
        );

        $this->assertEqualsWithDelta(600.0, (float) $log->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(2200.0, (float) $log->sermon_end_time, 0.01);
        $this->assertSame('llm_structure', $log->processing_metadata?->toArray()['sermon_bounds']['source'] ?? null);
    }

    #[Test]
    public function primary_mode_retries_detection_once_when_output_is_mechanically_impossible(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // First attempt puts the sermon end far beyond the 2430s recording —
        // corrupted detector output, not a semantic judgement (the 2023-02-26
        // corpus run emitted 41410s in a 4408s recording). A single fresh
        // attempt should recover instead of routing to manual review.
        MockServiceStructureService::useStructureSequence(
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->section('sermon', 600.0, 41410.0),
            ], model: 'mock'),
            $this->validStructure(),
        );

        $this->runJob($log);

        $log->refresh();
        $this->assertNotSame(ProcessingStatus::Failed, $log->status);
        $this->assertSame(4, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());

        $retry = $log->processing_metadata?->toArray()['service_structure_retry'] ?? null;
        $this->assertIsArray($retry);
        $this->assertContains('timestamps_outside_recording', $retry['failure_codes']);
    }

    #[Test]
    public function primary_mode_rechecks_a_readingless_sermon_and_adopts_the_retry(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // First pass validates but buries the reading inside a prayer — no
        // bible_reading section anywhere near the sermon (the 2024-11-03
        // corpus run absorbed the Luke reading into the pastoral prayer).
        // One feedback-guided retry should recover the reading.
        MockServiceStructureService::useStructureSequence(
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->section('prayer', 420.0, 590.0),
                $this->section('sermon', 600.0, 2200.0),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
            $this->validStructure(),
        );

        $this->runJob($log);

        $log->refresh();
        $this->assertNotSame(ProcessingStatus::Failed, $log->status);

        $types = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->pluck('section_type')
            ->map(fn ($type) => $type->value)
            ->all();
        $this->assertContains('bible_reading', $types);

        $recheck = $log->processing_metadata?->toArray()['service_structure_reading_recheck'] ?? null;
        $this->assertIsArray($recheck);
        $this->assertSame('retry_adopted', $recheck['outcome']);

        $this->assertNotSame([], MockServiceStructureService::lastFeedback());
    }

    #[Test]
    public function primary_mode_keeps_and_flags_a_readingless_structure_when_the_recheck_finds_nothing(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        $readingless = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('prayer', 420.0, 590.0),
            $this->section('sermon', 600.0, 2200.0),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'mock');
        MockServiceStructureService::useStructureSequence($readingless, $readingless);

        $this->runJob($log);

        $log->refresh();
        $this->assertNotSame(ProcessingStatus::Failed, $log->status, 'A missing reading never fails the run.');

        $recheck = $log->processing_metadata?->toArray()['service_structure_reading_recheck'] ?? null;
        $this->assertIsArray($recheck);
        $this->assertSame('reading_still_missing', $recheck['outcome']);

        $sermon = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'sermon')
            ->firstOrFail();
        $this->assertTrue((bool) $sermon->needs_manual_review);
        $this->assertContains(
            ServiceStructureValidator::FLAG_MISSING_PREACHED_READING,
            $sermon->metadata['review_flags'] ?? []
        );

        // The flag questions completeness, not the sermon's own boundaries —
        // the validated bounds must still replace the RMS guess.
        $this->assertEqualsWithDelta(600.0, (float) $log->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(2200.0, (float) $log->sermon_end_time, 0.01);
    }

    #[Test]
    public function primary_mode_keeps_the_passing_structure_when_the_recheck_errors(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // The first pass validates but buries the reading (no bible_reading near the sermon),
        // triggering the recheck. The recheck's detector call then errors — a transient OpenAI
        // timeout — which must not fail the already-valid run.
        $readingless = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('prayer', 420.0, 590.0),
            $this->section('sermon', 600.0, 2200.0),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'mock');
        MockServiceStructureService::useStructureThenThrow(
            $readingless,
            new \RuntimeException('OpenAI timed out'),
        );

        $this->runJob($log);

        $log->refresh();
        $this->assertNotSame(ProcessingStatus::Failed, $log->status, 'A recheck error must not fail an already-valid run.');

        $recheck = $log->processing_metadata?->toArray()['service_structure_reading_recheck'] ?? null;
        $this->assertIsArray($recheck);
        $this->assertSame('recheck_errored', $recheck['outcome']);

        // The original validated structure stands, sermon flagged for the reviewer.
        $sermon = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'sermon')
            ->firstOrFail();
        $this->assertTrue((bool) $sermon->needs_manual_review);
        $this->assertContains(
            ServiceStructureValidator::FLAG_MISSING_PREACHED_READING,
            $sermon->metadata['review_flags'] ?? []
        );
        $this->assertEqualsWithDelta(600.0, (float) $log->sermon_start_time, 0.01);
        $this->assertEqualsWithDelta(2200.0, (float) $log->sermon_end_time, 0.01);
    }

    #[Test]
    public function no_reading_recheck_runs_when_a_reading_sits_near_the_sermon(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        MockServiceStructureService::useStructure($this->validStructure());

        $this->runJob($log);

        $log->refresh();
        $this->assertArrayNotHasKey(
            'service_structure_reading_recheck',
            $log->processing_metadata?->toArray() ?? []
        );
        $this->assertSame([], MockServiceStructureService::lastFeedback());
    }

    #[Test]
    public function primary_mode_does_not_retry_semantic_validation_failures(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.email.admin_email', 'admin@example.com');
        Mail::fake();

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // Two sermons is a semantic failure — a retry would just burn tokens on
        // the same judgement. The valid structure queued behind it must never
        // be consumed.
        MockServiceStructureService::useStructureSequence(
            ServiceStructure::fromSections([
                $this->section('sermon', 0.0, 1000.0),
                $this->section('sermon', 1100.0, 2300.0),
            ], model: 'mock'),
            $this->validStructure(),
        );

        $this->runJob($log);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertSame('manual_review_required', $log->current_step);
        $this->assertArrayNotHasKey('service_structure_retry', $log->processing_metadata?->toArray() ?? []);
    }

    #[Test]
    public function primary_mode_routes_to_manual_review_when_the_retry_also_fails(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.email.admin_email', 'admin@example.com');
        Mail::fake();

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        $impossible = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('sermon', 600.0, 41410.0),
        ], model: 'mock');
        MockServiceStructureService::useStructureSequence($impossible, $impossible);

        $this->runJob($log);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertSame('manual_review_required', $log->current_step);

        // The retry was attempted and recorded; the persisted proposal is the
        // second attempt's, so the reviewer sees what the detector last said.
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $this->assertArrayHasKey('service_structure_retry', $metadata);
        $this->assertArrayHasKey('service_structure_proposal', $metadata);
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

    #[Test]
    public function a_reconcile_run_syncs_sections_without_touching_run_state(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'current_step' => 'completed',
        ]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        MockServiceStructureService::useStructure($this->validStructure());

        $this->runJob($log, reconcile: true);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Completed, $log->status, 'A reconcile re-run never re-opens a completed run.');
        $this->assertSame('completed', $log->current_step);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();
        $this->assertSame(
            ['welcome', 'bible_reading', 'sermon', 'song'],
            $sections->pluck('section_type')->map(fn ($type) => $type->value)->all()
        );
    }

    #[Test]
    public function a_reconcile_run_rolls_section_review_state_up_to_the_oos_backed_service(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $churchService = ChurchService::factory()->create(['needs_review' => false]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Opening Song',
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'current_step' => 'completed',
            'church_service_id' => $churchService->id,
        ]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // A sub-threshold sermon: the validator soft-flags it, so the synced
        // section needs manual review — that must reach the service inbox.
        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2200.0, confidence: 0.4),
            $this->section('song', 2210.0, 2400.0),
        ], ['Fixture structure.'], 'mock'));

        $this->runJob($log, reconcile: true);

        $this->assertTrue(
            $churchService->fresh()->needs_review,
            'A low-confidence reconcile re-detection must reach the review inbox.'
        );
    }

    #[Test]
    public function a_reconcile_run_keeps_existing_sections_and_run_state_on_hard_validation_failure(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.email.admin_email', 'admin@example.com');
        Mail::fake();

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'current_step' => 'completed',
        ]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        $existingSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => 'sermon',
            'section_order' => 1,
            'start_time' => 600.0,
            'end_time' => 2200.0,
        ]);
        $existingSnapshot = $existingSection->fresh()->toArray();

        // Two sermons — a hard validator failure.
        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 1000.0),
            $this->section('sermon', 1100.0, 2300.0),
        ], model: 'mock'));

        $this->runJob($log, reconcile: true);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Completed, $log->status, 'A failed reconcile re-detection never fails the run.');
        $this->assertSame('completed', $log->current_step);
        $this->assertArrayNotHasKey('manual_review', $log->processing_metadata?->toArray() ?? []);

        $this->assertSame(1, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
        $this->assertSame($existingSnapshot, $existingSection->fresh()->toArray(), 'Existing sections stay authoritative.');

        // The rejected proposal is still recorded for diagnosis.
        $proposal = $log->processing_metadata?->toArray()['service_structure_proposal'] ?? null;
        $this->assertIsArray($proposal);
        $this->assertFalse($proposal['passed_validation']);

        Mail::assertNotQueued(ManualReviewRequired::class);
    }

    private function runJob(MediaProcessingLog $log, bool $reconcile = false): void
    {
        (new DetectServiceStructure($log, $reconcile))->handle(
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

    private function section(string $type, float $start, float $end, float $confidence = 0.95, ?int $oosItemId = null): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => $confidence,
            'oos_item_id' => $oosItemId,
        ]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }
}
