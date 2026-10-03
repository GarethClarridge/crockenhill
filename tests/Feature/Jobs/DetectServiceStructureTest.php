<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Actions\HoldSectionForContentReview;
use App\Actions\ServiceReview\AnswerServiceStructureEnsembleQuestion;
use App\Contracts\ServiceStructureInterface;
use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ContentHoldCheck;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\AnalyzeSegments;
use App\Jobs\ClassifyServiceAudio;
use App\Jobs\DetectServiceStructure;
use App\Jobs\ExtractSermon;
use App\Jobs\GenerateRmsLog;
use App\Jobs\TranscribeFullService;
use App\Jobs\ValidateVideoFile;
use App\Livewire\Admin\ChurchServices\ShowChurchService;
use App\Mail\ManualReviewRequired;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\Structure\MockServiceStructureService;
use App\Services\ChurchService\Structure\ServiceStructureEnsembleReplay;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Processing\ProcessingPipelineBuilder;
use App\Services\Sermon\SermonCandidateConfidenceService;
use App\Services\Sermon\SermonExtractionPlanResolver;
use App\Support\SermonAutoExtractionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class DetectServiceStructureTest extends TestCase
{
    use CreatesHistoricImportOperations;
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

            public function detect(ChurchServiceTranscript $transcript, array $oosItems, ?string $processingId = null, array $feedback = [], ?AudioTimeline $audioTimeline = null, ?string $model = null): ServiceStructure
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
        $this->assertEqualsWithDelta(500.0, $shadow['diff']['sermon']['start_delta'], 0.01);
        $this->assertEqualsWithDelta(2000.0, $shadow['diff']['sermon']['end_delta'], 0.01);
    }

    #[Test]
    public function shadow_mode_runs_the_bound_model_when_authoritative_sections_are_missing(): void
    {
        Config::set('media-processing.service_structure.mode', 'shadow');
        Config::set('media-processing.service_structure.model', 'gpt-5');
        Config::set('media-processing.service_structure.shadow_model', 'gpt-6-candidate');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);

        $recoveredBoundStructure = $this->validStructure();
        $candidateStructure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 620.0, 2180.0),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'gpt-6-candidate');

        $detector = new class($recoveredBoundStructure, $candidateStructure) implements ServiceStructureInterface
        {
            /** @var list<string> */
            public array $modelsAtDetectTime = [];

            public function __construct(
                private readonly ServiceStructure $recoveredBoundStructure,
                private readonly ServiceStructure $candidateStructure,
            ) {}

            public function detect(ChurchServiceTranscript $transcript, array $oosItems, ?string $processingId = null, array $feedback = [], ?AudioTimeline $audioTimeline = null, ?string $model = null): ServiceStructure
            {
                $model = (string) config('media-processing.service_structure.model');
                $this->modelsAtDetectTime[] = $model;

                if ($model === 'gpt-6-candidate') {
                    return $this->candidateStructure;
                }

                return $this->recoveredBoundStructure;
            }
        };

        (new DetectServiceStructure($log))->handle(
            $detector,
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );

        $this->assertSame(['gpt-5', 'gpt-6-candidate'], $detector->modelsAtDetectTime);
        $this->assertSame('gpt-5', config('media-processing.service_structure.model'));

        $shadow = $log->refresh()->processing_metadata?->toArray()['service_structure_shadow'] ?? null;
        $this->assertIsArray($shadow);
        $this->assertSame(['gpt-5'], $shadow['diff']['baseline']['models'] ?? null);
        $this->assertEqualsWithDelta(0.0, $shadow['diff']['sermon']['start_delta'], 0.01);
        $this->assertEqualsWithDelta(0.0, $shadow['diff']['sermon']['end_delta'], 0.01);
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

            public function detect(ChurchServiceTranscript $transcript, array $oosItems, ?string $processingId = null, array $feedback = [], ?AudioTimeline $audioTimeline = null, ?string $model = null): ServiceStructure
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

        $this->assertSame(['gpt-5'], $detector->modelsAtDetectTime);
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
        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section(
                'sermon',
                600.0,
                2200.0,
                summary: 'The sermon explains God’s faithfulness from Joshua chapter one.'
            ),
            $this->section('song', 2210.0, 2400.0),
        ], ['Fixture structure.'], 'mock', 'The service teaches from Joshua chapter one.', [
            ['title' => 'Holiday club', 'details' => 'Registration opens next week.'],
        ], [
            ['title' => 'Sermon', 'start_time' => 600.0, 'end_time' => 2200.0],
        ]));

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
        $structurePayload = $log->processing_metadata?->toArray()['service_structure'] ?? [];
        $this->assertSame('The service teaches from Joshua chapter one.', $structurePayload['summary']);
        $this->assertSame('Holiday club', $structurePayload['notices'][0]['title']);
        $this->assertSame('The sermon explains God’s faithfulness from Joshua chapter one.', $structurePayload['sections'][2]['summary']);
    }

    /**
     * The 963 shape: a carol the transcript cannot see, between the welcome and the reading.
     * The detector returns no section for it; the RMS log shows it, and the proposal waits
     * for review rather than publishing.
     */
    #[Test]
    public function primary_mode_proposes_a_held_song_for_singing_the_detector_left_unsectioned(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        $this->storeRmsLog($log, sungFrom: 130, sungTo: 410);
        MockServiceStructureService::useStructure($this->validStructure());

        $this->runJob($log);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->get();

        $this->assertSame(
            ['welcome', 'song', 'bible_reading', 'sermon', 'song'],
            $sections->pluck('section_type')->map(fn ($type) => $type->value)->all()
        );
        $this->assertSame('Unidentified singing', $sections[1]->title);
        $this->assertTrue($sections[1]->needs_manual_review);
        $this->assertContains(
            ServiceStructureValidator::FLAG_UNIDENTIFIED_SINGING,
            $sections[1]->metadata?->toArray()['review_flags'] ?? [],
        );
    }

    /**
     * The blind comparison's commonest shape: the detector starts a song where the leader
     * announces it, so the clip would open on 40 s of "Let's stand and sing…".
     */
    #[Test]
    public function primary_mode_trims_a_spoken_announcement_off_the_start_of_a_song(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        $this->storeRmsLog($log, sungFrom: 170, sungTo: 410, alsoSung: [[2210, 2400]]);
        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('song', 130.0, 410.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2200.0),
            $this->section('song', 2210.0, 2400.0),
        ], ['Fixture structure.'], 'mock'));

        $this->runJob($log);

        $song = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'song')
            ->orderBy('section_order')
            ->firstOrFail();

        $this->assertEqualsWithDelta(160.0, $song->start_time, 5.0);
        $this->assertLessThanOrEqual(170.0, $song->start_time);
        $this->assertStringContainsString('Start trimmed', implode(' ', $song->metadata?->toArray()['ai_notes'] ?? []));
    }

    /**
     * §1301's shape reaching the pipeline: a section typed as something other than a song whose
     * audio is sung without a break and whose transcript holds almost no words. The structure
     * stage records that it reads as sung; the extraction planner decides what it means, because
     * only there is it known whether the sermon's span swallowed it.
     */
    #[Test]
    public function primary_mode_flags_a_non_song_section_whose_audio_is_sung(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        $this->storeRmsLog($log, sungFrom: 420, sungTo: 590);
        MockServiceStructureService::useStructure($this->validStructure());

        $this->runJob($log);

        $reading = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', ServiceSectionType::BibleReading->value)
            ->firstOrFail();

        $this->assertContains(
            ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG,
            $reading->metadata?->toArray()['review_flags'] ?? [],
        );
    }

    /**
     * The flag must never land on a sermon, whatever its audio.
     * {@see SermonAutoExtractionPolicy} permits automatic extraction only when every
     * flag on the chosen section is registered as non-disqualifying, so an unregistered flag here
     * would quietly stop the sermon extracting. This paints the sermon's own span as unbroken
     * sound, where only the type exclusion stands between it and the flag.
     */
    #[Test]
    public function primary_mode_never_flags_a_sermon_section_as_reading_sung(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        $this->storeRmsLog($log, sungFrom: 600, sungTo: 2200);
        MockServiceStructureService::useStructure($this->validStructure());

        $this->runJob($log);

        $sermon = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', ServiceSectionType::Sermon->value)
            ->firstOrFail();

        $this->assertNotContains(
            ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG,
            $sermon->metadata?->toArray()['review_flags'] ?? [],
        );
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
            ClassifyServiceAudio::class,
            DetectServiceStructure::class,
            ExtractSermon::class,
        ], array_map(
            static fn (object $job): string => $job::class,
            array_slice($pipeline, 0, 7),
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
        $this->assertEqualsWithDelta(600.0, $extractionPlan['segments'][0]['start_time'], 0.01);
        $this->assertTrue($extractionPlan['metadata']['requires_review']);
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
        $this->assertSame('service_sections', $extractionPlan['source']);
        $this->assertSame('sermon_composition_review', $extractionPlan['metadata']['reason']);
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
            $this->referencedSection('bible_reading', 420.0, 590.0, readingReference: 'John 3'),
            $this->section('sermon', 600.0, 2200.0, oosItemId: (int) $sermonItem->id, sermonReference: 'John 3'),
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

    /**
     * Run 949's shape: every item was written by the run's own earlier projection, so
     * following them would only repeat the last round's answer (canary 2, 2026-09-24).
     */
    #[Test]
    public function detection_never_sees_order_of_service_items_only_the_recording_attests(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $churchService = ChurchService::factory()->create();
        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'title' => 'Elmstead share and prayer',
            'position' => 1,
            'metadata' => ['source_evidence' => ['livestream' => ['section_id' => 1]]],
        ]);
        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'title' => 'Court Farm share and prayer',
            'position' => 2,
            'metadata' => ['source_evidence' => ['livestream' => ['section_id' => 2]]],
        ]);

        $detector = $this->detectorCapturingItems();
        $this->runDetection($churchService, $detector);

        $this->assertSame(array_fill(0, 4, []), $detector->itemsSeen);
    }

    #[Test]
    public function detection_sees_items_an_email_openlp_or_manual_source_attests(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $churchService = ChurchService::factory()->create();
        $mergedIntoEmail = ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'title' => 'Praise My Soul',
            'position' => 1,
            'metadata' => ['source_evidence' => ['livestream' => ['section_id' => 1], 'email' => ['line' => 3]]],
        ]);
        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'title' => 'Church sharing and prayer',
            'position' => 2,
            'metadata' => ['source_evidence' => ['livestream' => ['section_id' => 2]]],
        ]);
        $openLp = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'title' => 'Sermon',
            'position' => 3,
            'metadata' => ['source_evidence' => ['openlp' => ['item' => 7]]],
        ]);
        $manual = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'source' => 'manual',
            'title' => 'Closing prayer',
            'position' => 4,
            'metadata' => null,
        ]);

        $detector = $this->detectorCapturingItems();
        $this->runDetection($churchService, $detector);

        $this->assertSame(
            array_fill(0, 4, [(int) $mergedIntoEmail->id, (int) $openLp->id, (int) $manual->id]),
            array_map(fn (array $items): array => array_column($items, 'id'), $detector->itemsSeen),
        );
    }

    #[Test]
    public function invalid_draw_does_not_vote_and_survivors_require_review(): void
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
        $this->assertSame(ProcessingStatus::Processing, $log->status);
        $this->assertSame(4, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());

        $attempt = $log->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? null;
        $this->assertIsArray($attempt);
        $this->assertSame(['invalid', 'valid', 'valid', 'valid'], array_column($attempt['outcomes'], 'status'));
        $this->assertTrue($attempt['composition']['degraded']);
    }

    #[Test]
    public function disagreement_about_a_preached_reading_is_banked_for_review(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // First pass validates but buries the reading inside a prayer — no
        // bible_reading section anywhere near the sermon (the 2024-11-03
        // corpus run absorbed the Luke reading into the pastoral prayer).
        // One feedback-guided retry should recover the reading.
        // Two of four drafts each way: a three-to-one vote would settle it (ruled 2026-10-01).
        MockServiceStructureService::useStructureSequence(
            $this->validStructure(),
            $this->validStructure(),
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->section('prayer', 420.0, 590.0),
                $this->section('sermon', 600.0, 2200.0),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
        );

        $this->runJob($log);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Processing, $log->status);

        $types = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->orderBy('section_order')
            ->pluck('section_type')
            ->map(fn ($type) => $type->value)
            ->all();
        $this->assertContains('bible_reading', $types);

        $attempt = $log->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? null;
        $this->assertIsArray($attempt);
        $this->assertNotEmpty($attempt['composition']['disputes']);
        $this->assertArrayNotHasKey('service_structure_reading_recheck', $log->processing_metadata?->toArray() ?? []);
        $this->assertSame([], MockServiceStructureService::lastFeedback());
    }

    #[Test]
    public function unanimous_readingless_structure_keeps_missing_reading_review_flag(): void
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

        $this->assertArrayNotHasKey('service_structure_reading_recheck', $log->processing_metadata?->toArray() ?? []);

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
    public function three_unavailable_draws_do_not_promote_one_valid_structure(): void
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
        $this->assertSame(ProcessingStatus::Failed, $log->status);
        $this->assertSame(0, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
        $attempt = $log->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? null;
        $this->assertIsArray($attempt);
        $this->assertSame(['valid', 'unavailable', 'unavailable', 'unavailable'], array_column($attempt['outcomes'], 'status'));
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
    public function competing_reading_references_are_recorded_as_ensemble_disputes(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // The Psalm read before the sermon is not the Luke passage it preaches;
        // the retry recovers Luke and keeps the talk within a re-draw's jitter.
        $recovered = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('short_talk', 136.0, 392.0),
            $this->referencedSection('bible_reading', 400.0, 470.0, readingReference: 'Psalm 23'),
            $this->referencedSection('bible_reading', 480.0, 590.0, readingReference: 'Luke 15:1-10'),
            $this->referencedSection('sermon', 600.0, 2200.0, sermonReference: 'Luke 15:1-10'),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'mock');
        // Two of four drafts each way: a three-to-one vote would settle it (ruled 2026-10-01).
        MockServiceStructureService::useStructureSequence(
            $recovered,
            $recovered,
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->section('short_talk', 130.0, 400.0),
                $this->referencedSection('bible_reading', 420.0, 590.0, readingReference: 'Psalm 23'),
                $this->referencedSection('sermon', 600.0, 2200.0, sermonReference: 'Luke 15:1-10'),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
        );

        $this->runJob($log);

        $log->refresh();
        $attempt = $log->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? null;
        $this->assertIsArray($attempt);
        $this->assertNotEmpty($attempt['composition']['disputes']);
        $this->assertSame([], MockServiceStructureService::lastFeedback());
        $this->assertSame(2, ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'bible_reading')
            ->count());
    }

    #[Test]
    public function an_answer_carries_to_a_fresh_ensemble_of_the_same_recording(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        [$service, $log, $dispute, $admin] = $this->disputedReadingRun();

        app(AnswerServiceStructureEnsembleQuestion::class)->execute($log->id, $dispute['question_id'], 'choose', $admin, slot: 1);

        MockServiceStructureService::useStructureSequence(...$this->readingDisputeDraws());
        $this->runJob($log->fresh());

        $bank = $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'] ?? [];
        $latest = end($bank);

        $this->assertCount(2, $bank);
        $this->assertNotSame($bank[0]['attempt_id'], $latest['attempt_id']);
        $this->assertSame([], array_values(array_filter($latest['composition']['disputes'], static fn (array $open): bool => $open['type'] === 'bible_reading')));
        $this->assertCount(1, $latest['composition']['applied_rulings']);
        $this->assertSame('Luke 15:1-10', ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'bible_reading')
            ->firstOrFail()
            ->metadata?->readingReference);
    }

    #[Test]
    public function answering_on_a_run_with_extracted_media_banks_the_ruling_without_resyncing_sections(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        [$service, $log, $dispute, $admin] = $this->disputedReadingRun();
        $reading = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'bible_reading')
            ->firstOrFail();
        $sermon = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'sermon')
            ->firstOrFail();
        $sermon->forceFill(['extracted_audio_path' => 'sections/extracted-sermon.m4a'])->save();
        Storage::disk($sermon->extractedAssetDisk())->put('sections/extracted-sermon.m4a', 'audio');

        $result = app(AnswerServiceStructureEnsembleQuestion::class)->execute($log->id, $dispute['question_id'], 'choose', $admin, slot: 1);

        $this->assertFalse($result['sections_synced']);
        $this->assertSame('Psalm 23', $reading->fresh()?->metadata?->readingReference);
        $this->assertSame('sections/extracted-sermon.m4a', $sermon->fresh()?->extracted_audio_path);
        $this->assertTrue(Storage::disk($sermon->extractedAssetDisk())->exists('sections/extracted-sermon.m4a'));
        $this->assertCount(1, $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble_rulings'] ?? []);

        Storage::disk($sermon->extractedAssetDisk())->delete('sections/extracted-sermon.m4a');
    }

    #[Test]
    public function a_recompose_request_applies_the_answer_to_the_reviewed_draws_without_drawing_again(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        [$service, $log, $dispute, $admin] = $this->disputedReadingRun();
        $sermon = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'sermon')
            ->firstOrFail();
        $sermon->forceFill(['extracted_audio_path' => 'sections/extracted-sermon.m4a'])->save();
        Storage::disk($sermon->extractedAssetDisk())->put('sections/extracted-sermon.m4a', 'audio');
        app(AnswerServiceStructureEnsembleQuestion::class)->execute($log->id, $dispute['question_id'], 'choose', $admin, slot: 1);
        $attemptId = $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'][0]['attempt_id'];
        $this->requestRecompose($log, $attemptId);
        // A fresh draw would write this structure, with no reading at all.
        MockServiceStructureService::useStructure($this->validStructure());

        $this->runJob($log->fresh());

        $metadata = $log->fresh()?->processing_metadata?->toArray() ?? [];
        $this->assertCount(1, $metadata['service_structure_ensemble']);
        $this->assertSame([], $metadata['service_structure_ensemble'][0]['composition']['disputes']);
        $this->assertCount(1, $metadata['service_structure_ensemble'][0]['composition']['applied_rulings']);
        $this->assertArrayHasKey('recomposed_at', $metadata['service_structure_ensemble'][0]['composition']);
        $this->assertArrayNotHasKey(DetectServiceStructure::RECOMPOSE_KEY, $metadata);
        $this->assertSame('Luke 15:1-10', ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'bible_reading')
            ->firstOrFail()
            ->metadata?->readingReference);
        $this->assertSame('sections/extracted-sermon.m4a', $sermon->fresh()?->extracted_audio_path);
        $this->assertNotSame(ProcessingStatus::Failed, $log->fresh()?->status);

        Storage::disk($sermon->extractedAssetDisk())->delete('sections/extracted-sermon.m4a');
    }

    #[Test]
    public function a_recompose_can_write_its_structure_after_projection_renumbers_source_items(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        $service = ChurchService::factory()->create();
        $item = ChurchServiceItem::factory()->for($service)->create([
            'position' => 9,
            'title' => 'Reading 2',
            'metadata' => null,
        ]);
        $log = MediaProcessingLog::factory()->livestream()->pending()->create(['church_service_id' => $service->id]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        MockServiceStructureService::useStructure($this->validStructure());
        $this->runJob($log);
        $attemptId = $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'][0]['attempt_id'];

        $item->update(['position' => 11]);
        $this->requestRecompose($log, $attemptId);
        $this->runJob($log->fresh());

        $metadata = $log->fresh()?->processing_metadata?->toArray() ?? [];
        $this->assertCount(1, $metadata['service_structure_ensemble']);
        $this->assertSame($attemptId, $metadata['service_structure_ensemble'][0]['attempt_id']);
        $this->assertSame([], $metadata['service_structure_ensemble'][0]['composition']['disputes']);
        $this->assertArrayNotHasKey(DetectServiceStructure::RECOMPOSE_KEY, $metadata);
        $this->assertNotSame(ProcessingStatus::Failed, $log->fresh()?->status);
        $this->assertGreaterThan(0, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
    }

    #[Test]
    public function a_recompose_request_refuses_rather_than_draws_when_the_banked_input_is_stale(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        [$service, $log, $dispute, $admin] = $this->disputedReadingRun();
        $attemptId = $log->processing_metadata?->toArray()['service_structure_ensemble'][0]['attempt_id'];
        $this->requestRecompose($log, $attemptId);
        // Re-transcription replaced the text the answers were given on.
        Storage::disk('local')->put('temp/service_transcript_'.$log->processing_id.'.json', (string) json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 0.0, 'end' => 2400.0, 'text' => 'A different decode of the same service.'],
        ], 2430.0, ChurchServiceTranscript::SOURCE_MOCK)));

        try {
            $this->runJob($log->fresh());
            $this->fail('A recompose over a changed input must refuse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no longer matches', $exception->getMessage());
        }

        $this->assertCount(1, $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'] ?? []);
    }

    #[Test]
    public function a_recompose_request_for_an_older_attempt_refuses_rather_than_draws(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        [$service, $log, $dispute, $admin] = $this->disputedReadingRun();
        $this->requestRecompose($log, 'an-earlier-attempt');

        try {
            $this->runJob($log->fresh());
            $this->fail('A recompose of anything but the latest attempt must refuse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no longer this run\'s latest', $exception->getMessage());
        }

        $this->assertCount(1, $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'] ?? []);
    }

    private function requestRecompose(MediaProcessingLog $log, string $attemptId): void
    {
        $log->writeProcessingMetadata(static function (array $metadata) use ($attemptId): array {
            $metadata[DetectServiceStructure::RECOMPOSE_KEY] = ['attempt_id' => $attemptId, 'requested_at' => now()->toIso8601String()];

            return $metadata;
        });
    }

    #[Test]
    public function the_review_panel_plays_the_recording_and_takes_a_structured_correction(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        [$service, $log, $dispute, $admin] = $this->disputedReadingRun();
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $metadata['service_artifacts'][] = ['kind' => 'audio', 'disk' => 'local', 'path' => 'service-audio/2026-03-22/morning-x.mp3'];
        $log->forceFill(['processing_metadata' => $metadata])->save();

        $component = Livewire::actingAs($admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee(route('admin.recordings.service-audio', $log).'#t=410,600', false)
            ->assertDontSee('Replacement sections as JSON')
            ->call('startEnsembleCorrection', $log->id, $dispute['question_id'])
            ->assertSet("ensembleCorrections.{$dispute['question_id']}.0.start", '7:00')
            ->set("ensembleCorrections.{$dispute['question_id']}.0.end", 'later')
            ->call('answerEnsembleQuestion', $log->id, $dispute['question_id'], 'correct');

        $this->assertSame([], $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble_rulings'] ?? []);

        $component
            ->set("ensembleCorrections.{$dispute['question_id']}.0.end", '9:50')
            ->set("ensembleCorrections.{$dispute['question_id']}.0.reference", 'Luke 15:1-10')
            ->call('answerEnsembleQuestion', $log->id, $dispute['question_id'], 'correct')
            ->assertHasNoErrors();

        $ruling = $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble_rulings'][0] ?? [];
        $this->assertSame('correct', $ruling['kind']);
        $this->assertEquals([[
            'type' => 'bible_reading',
            'title' => null,
            'start_time' => 420.0,
            'end_time' => 590.0,
            'confidence' => 1.0,
            'song_title' => null,
            'reading_reference' => 'Luke 15:1-10',
            'sermon_reference' => null,
        ]], $ruling['resolution']['sections']);
    }

    /** @return array{0: ChurchService, 1: MediaProcessingLog, 2: array<string, mixed>, 3: User} */
    private function disputedReadingRun(): array
    {
        $service = ChurchService::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create(['church_service_id' => $service->id]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        MockServiceStructureService::useStructureSequence(...$this->readingDisputeDraws());

        $this->runJob($log);
        $dispute = collect($log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'][0]['composition']['disputes'] ?? [])
            ->firstWhere('type', 'bible_reading');
        $this->assertIsArray($dispute);

        return [$service, $log->fresh(), $dispute, User::factory()->admin()->create()];
    }

    /**
     * A 2–2 split on the reading's reference: the tie-break slot 0 writes Psalm 23.
     *
     * @return list<ServiceStructure>
     */
    private function readingDisputeDraws(): array
    {
        $draws = [
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->referencedSection('bible_reading', 420.0, 590.0, readingReference: 'Psalm 23'),
                $this->referencedSection('sermon', 600.0, 2200.0, sermonReference: 'Luke 15:1-10'),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->referencedSection('bible_reading', 420.0, 590.0, readingReference: 'Luke 15:1-10'),
                $this->referencedSection('sermon', 600.0, 2200.0, sermonReference: 'Luke 15:1-10'),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
        ];

        return [...$draws, ...$draws];
    }

    #[Test]
    public function an_operator_answer_corrects_banked_structure_without_resuming_the_run(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $service = ChurchService::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create(['church_service_id' => $service->id]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        $psalm = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->referencedSection('bible_reading', 420.0, 590.0, readingReference: 'Psalm 23'),
            $this->referencedSection('sermon', 600.0, 2200.0, sermonReference: 'Luke 15:1-10'),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'mock');
        $luke = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->referencedSection('bible_reading', 420.0, 590.0, readingReference: 'Luke 15:1-10'),
            $this->referencedSection('sermon', 600.0, 2200.0, sermonReference: 'Luke 15:1-10'),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'mock');
        // Two of four drafts each way: a three-to-one vote would settle it (ruled 2026-10-01).
        MockServiceStructureService::useStructureSequence($luke, $luke, $psalm);

        $this->runJob($log);
        $log->refresh();
        $this->assertSame(ProcessingStatus::Processing, $log->status);
        $dispute = collect($log->processing_metadata?->toArray()['service_structure_ensemble'][0]['composition']['disputes'] ?? [])
            ->firstWhere('type', 'bible_reading');
        $this->assertIsArray($dispute);
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->assertSee('Structure questions')
            ->assertSee('Recorded alternatives');

        $transcriptPath = $log->serviceTranscriptPath();
        $originalTranscript = Storage::disk('local')->get($transcriptPath);
        Storage::disk('local')->put($transcriptPath, $originalTranscript.' ');

        try {
            app(AnswerServiceStructureEnsembleQuestion::class)->execute(
                $log->id,
                $dispute['question_id'],
                'choose',
                $admin,
                slot: 1,
            );
            $this->fail('Changed source evidence should block the answer.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no longer matches', $exception->getMessage());
        } finally {
            Storage::disk('local')->put($transcriptPath, $originalTranscript);
        }

        $this->assertSame([], $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble_rulings'] ?? []);

        Livewire::actingAs($admin)
            ->test(ShowChurchService::class, ['churchService' => $service])
            ->call('answerEnsembleQuestion', $log->id, $dispute['question_id'], 'choose', 1)
            ->assertHasNoErrors()
            ->assertDontSee('Structure questions');

        $metadata = $log->fresh()?->processing_metadata?->toArray() ?? [];
        $answer = app(ServiceStructureEnsembleReplay::class)->replay(
            $metadata['service_structure_ensemble'][0],
            $metadata['service_structure_ensemble_rulings'],
        );

        $this->assertSame([], $answer['disputes']);
        $this->assertSame('Luke 15:1-10', $answer['structure']['sections'][1]['reading_reference']);
        $this->assertSame(ProcessingStatus::Processing, $log->fresh()?->status);
        $this->assertCount(1, $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble_rulings'] ?? []);
        $this->assertArrayHasKey('extraction_plan', $metadata['service_structure_ensemble'][0]['composition']);
    }

    #[Test]
    public function a_nearby_reading_of_the_preached_passage_needs_no_recheck(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->referencedSection('bible_reading', 420.0, 590.0, readingReference: 'Luke 15:1-7'),
            $this->referencedSection('sermon', 600.0, 2200.0, sermonReference: 'Luke 15:1-10'),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'mock'));

        $this->runJob($log);

        $this->assertArrayNotHasKey(
            'service_structure_reading_recheck',
            $log->refresh()->processing_metadata?->toArray() ?? []
        );
    }

    #[Test]
    public function talk_boundary_disagreement_remains_held_when_reading_is_recovered(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // The retry recovers the reading but, being a fresh draw, also cuts the
        // talk short. A reading is not worth a truncated talk: keep the original.
        $recovered = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('short_talk', 130.0, 250.0),
            $this->section('other', 250.0, 400.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2200.0),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'mock');
        // Two of four drafts each way: a three-to-one vote would settle it (ruled 2026-10-01).
        MockServiceStructureService::useStructureSequence(
            $recovered,
            $recovered,
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->section('short_talk', 130.0, 400.0),
                $this->section('prayer', 420.0, 590.0),
                $this->section('sermon', 600.0, 2200.0),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
        );

        $this->runJob($log);

        $log->refresh();
        $attempt = $log->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? null;
        $this->assertIsArray($attempt);
        $this->assertNotEmpty($attempt['composition']['disputes']);

        $talk = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'short_talk')
            ->sole();
        $this->assertContains(ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES, $talk->metadata['review_flags'] ?? []);

        $sermon = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'sermon')
            ->sole();
        $this->assertNotContains(
            ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES,
            $sermon->metadata['review_flags'] ?? []
        );
    }

    #[Test]
    public function invalid_semantic_draw_is_banked_without_a_retry(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.email.admin_email', 'admin@example.com');
        Mail::fake();

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        // Two sermons can be detector instability rather than genuine ambiguity.
        // One feedback-guided attempt gets the exact validator finding and may recover.
        MockServiceStructureService::useStructureSequence(
            ServiceStructure::fromSections([
                $this->section('sermon', 0.0, 1000.0),
                $this->section('sermon', 1100.0, 2300.0),
            ], model: 'mock'),
            $this->validStructure(),
        );

        $this->runJob($log);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Processing, $log->status);
        $attempt = $log->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? null;
        $this->assertIsArray($attempt);
        $this->assertSame('invalid', $attempt['outcomes'][0]['status']);
        $this->assertArrayNotHasKey('service_structure_retry', $log->processing_metadata?->toArray() ?? []);
        $this->assertSame([], MockServiceStructureService::lastFeedback());
    }

    /**
     * Canary 8, run 949: the first attempt nested one prayer inside another during a time of
     * sharing and prayer. Regenerated from scratch, the retry fixed the chronology by typing
     * the church updates as talks — publishable content the failure had nothing to do with.
     */
    #[Test]
    public function invalid_chronology_draw_is_preserved_without_feedback_regeneration(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        MockServiceStructureService::useStructureSequence(
            $this->nestedPrayersStructure(),
            $this->validStructure(),
        );

        $this->runJob($log);

        $this->assertSame([], MockServiceStructureService::lastFeedback());
        $attempt = $log->refresh()->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? null;
        $this->assertIsArray($attempt);
        $this->assertSame('invalid', $attempt['outcomes'][0]['status']);
        $raw = Storage::disk($attempt['artifact_disk'])->get($attempt['slots'][0]['path']);
        $this->assertIsString($raw);
        $invalid = json_decode($raw, true);
        $this->assertSame(
            ['welcome', 'prayer', 'prayer', 'bible_reading', 'sermon', 'song'],
            array_column($invalid['validated']['sections'], 'type'),
        );
    }

    #[Test]
    public function talk_from_three_surviving_draws_is_flagged_as_degraded(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        MockServiceStructureService::useStructureSequence(
            $this->nestedPrayersStructure(),
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->section('short_talk', 130.0, 400.0),
                $this->section('bible_reading', 420.0, 590.0),
                $this->section('sermon', 600.0, 2200.0),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
        );

        $this->runJob($log);

        $log->refresh();
        $talk = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'short_talk')
            ->sole();
        $this->assertContains(ServiceStructureValidator::FLAG_ENSEMBLE_DEGRADED, $talk->metadata['review_flags'] ?? []);
        $this->assertArrayNotHasKey('service_structure_retry', $log->processing_metadata?->toArray() ?? []);
    }

    #[Test]
    public function invalid_draw_talk_cannot_outvote_three_prayer_draws(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        MockServiceStructureService::useStructureSequence(
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->section('short_talk', 130.0, 400.0),
                $this->section('bible_reading', 420.0, 590.0),
                $this->section('bible_reading', 430.0, 580.0),
                $this->section('sermon', 600.0, 2200.0),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->section('prayer', 130.0, 400.0),
                $this->section('bible_reading', 420.0, 590.0),
                $this->section('sermon', 600.0, 2200.0),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
        );

        $this->runJob($log);

        $prayer = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', 'prayer')
            ->sole();
        $this->assertContains(ServiceStructureValidator::FLAG_ENSEMBLE_DEGRADED, $prayer->metadata['review_flags'] ?? []);
        $this->assertSame(0, ServiceSection::query()->where('media_processing_log_id', $log->id)->where('section_type', 'short_talk')->count());
    }

    #[Test]
    public function stable_talk_from_surviving_draws_still_carries_degraded_flag(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        MockServiceStructureService::useStructureSequence(
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->section('short_talk', 130.0, 400.0),
                $this->section('bible_reading', 420.0, 590.0),
                $this->section('bible_reading', 430.0, 580.0),
                $this->section('sermon', 600.0, 2200.0),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
            ServiceStructure::fromSections([
                $this->section('welcome', 0.0, 120.0),
                $this->section('short_talk', 130.0, 400.0),
                $this->section('bible_reading', 420.0, 590.0),
                $this->section('sermon', 600.0, 2200.0),
                $this->section('song', 2210.0, 2400.0),
            ], model: 'mock'),
        );

        $this->runJob($log);

        $log->refresh();
        $flagged = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->get()
            ->filter(fn (ServiceSection $section): bool => in_array(
                ServiceStructureValidator::FLAG_ENSEMBLE_DEGRADED,
                $section->metadata['review_flags'] ?? [],
                true,
            ));
        $this->assertGreaterThan(0, $flagged->count());
        $this->assertArrayNotHasKey('service_structure_retry', $log->processing_metadata?->toArray() ?? []);
    }

    #[Test]
    public function primary_mode_routes_to_manual_review_when_all_draws_fail(): void
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

        // Every rejected draw is banked, and none becomes an authoritative section.
        $metadata = $log->processing_metadata?->toArray() ?? [];
        $this->assertArrayNotHasKey('service_structure_retry', $metadata);
        $this->assertCount(4, $metadata['service_structure_ensemble'][0]['outcomes']);
        $this->assertSame(['invalid', 'invalid', 'invalid', 'invalid'], array_column($metadata['service_structure_ensemble'][0]['outcomes'], 'status'));
    }

    #[Test]
    public function primary_mode_routes_hard_validation_failures_to_manual_review(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.email.admin_email', 'admin@example.com');
        Mail::fake();

        $operation = $this->createHistoricImportOperation();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'historic_import_operation_id' => $operation->id,
            'processing_metadata' => ['historic_import' => ['operation_id' => $operation->operation_id, 'job_key' => 'structure-review']],
        ]);
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
        $this->assertStringContainsString('Fewer than two validated ensemble draws', (string) $log->error_message);
        $this->assertSame(0, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
        $this->assertSame([], $job->chained, 'The remaining chained jobs are cancelled.');

        // The rejected proposal survives for the reviewer and for scoring —
        // without creating sections or synthesising segments.
        $proposal = $log->processing_metadata?->toArray()['service_structure_proposal'] ?? null;
        $this->assertIsArray($proposal);
        $this->assertFalse($proposal['passed_validation']);
        $this->assertContains('insufficient_ensemble_votes', array_column($proposal['hard_failures'], 'code'));
        $this->assertCount(0, $proposal['sections']);
        $attempt = $log->processing_metadata?->toArray()['service_structure_ensemble'][0] ?? null;
        $this->assertIsArray($attempt);
        $this->assertSame(['invalid', 'invalid', 'invalid', 'invalid'], array_column($attempt['outcomes'], 'status'));
        $this->assertSame(3, LivestreamSegment::query()->where('media_processing_log_id', $log->id)->count());

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('historic_import_alerts', [
            'historic_import_operation_id' => $operation->id,
            'kind' => 'manual_review_structure',
        ]);
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

    /**
     * Run 1314's canary shape: a song section was held because its bounds were
     * wrong, the fixed transcript no longer contains a song there, and the sync
     * guard refuses the replacement. The refusal is deterministic, so the job
     * must park rather than throw — a throw put it back on the queue, and each
     * retry paid for a fresh detection that could have re-created the very span
     * the hold was placed against.
     */
    #[Test]
    public function an_unplaced_content_hold_parks_the_run_instead_of_retrying_the_paid_detection(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Config::set('media-processing.email.admin_email', 'admin@example.com');
        Mail::fake();

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        $held = $this->heldSection($log, ServiceSectionType::Song, sectionOrder: 4, startTime: 2210.0, endTime: 2400.0);

        // The repaired structure keeps the sermon but finds no song at the end.
        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2400.0),
        ], ['Fixture structure.'], 'mock'));

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
        $this->assertSame('unplaced_content_hold', $log->manualReviewMetadata()['reason_code'] ?? null);
        $this->assertSame([], $job->chained, 'The remaining chained jobs are cancelled.');

        // Nothing was written: the held section keeps its type, bounds and hold.
        $held->refresh();
        $this->assertSame(ServiceSectionType::Song, $held->section_type);
        $this->assertSame(2210.0, (float) $held->start_time);
        $this->assertSame(1, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());

        // The refused replacement is inspectable, and distinguishable from a
        // proposal that failed validation: this one passed.
        $proposal = $log->processing_metadata?->toArray()['service_structure_proposal'] ?? null;
        $this->assertIsArray($proposal);
        $this->assertTrue($proposal['passed_validation']);
        $this->assertSame('unplaced_content_hold', $proposal['refused_reason']);
        $this->assertSame([$held->id], $proposal['unplaced_content_hold_section_ids']);
        $this->assertCount(3, $proposal['sections']);
    }

    /**
     * A reconcile re-detection runs against a completed run, so a refusal there
     * must leave the run and its sections exactly as they were.
     */
    #[Test]
    public function a_reconcile_refusal_leaves_the_completed_run_and_its_sections_alone(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Mail::fake();

        $log = MediaProcessingLog::factory()->livestream()->completed()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        $held = $this->heldSection($log, ServiceSectionType::Song, sectionOrder: 4, startTime: 2210.0, endTime: 2400.0);

        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2400.0),
        ], ['Fixture structure.'], 'mock'));

        $this->runJob($log, reconcile: true);

        $log->refresh();
        $this->assertSame(ProcessingStatus::Completed, $log->status);
        $this->assertSame(1, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
        $this->assertSame(ServiceSectionType::Song, $held->refresh()->section_type);

        Mail::assertNothingQueued();
    }

    /**
     * Operator ruling 2026-09-23: after a repair, the check that found a hold is
     * re-run over the repaired transcript and the hold clears if it now passes.
     */
    #[Test]
    public function detection_over_a_repaired_transcript_rechecks_the_holds_its_checks_can_retest(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        Mail::fake();

        $log = MediaProcessingLog::factory()->livestream()->completed()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 3,
            'title' => null,
            'start_time' => 600.0,
            'end_time' => 2400.0,
            'duration' => 1800.0,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);
        app(HoldSectionForContentReview::class)($sermon, 'Saved sermon text repeats a loop', 'residue register', ContentHoldCheck::LoopScreen);

        // The hold was found on the transcript as it stood before retranscription.
        $metadata = $sermon->refresh()->metadata?->toArray() ?? [];
        $metadata[HoldSectionForContentReview::METADATA_KEY][0]['transcript_sha256'] = 'before-repair';
        $sermon->forceFill(['metadata' => $metadata])->save();

        MockServiceStructureService::useStructure(ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2400.0),
        ], ['Fixture structure.'], 'mock'));

        $this->runJob($log, reconcile: true);

        $repaired = ServiceSection::query()
            ->where('media_processing_log_id', $log->id)
            ->where('section_type', ServiceSectionType::Sermon->value)
            ->firstOrFail();
        $record = $repaired->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY][0] ?? [];

        $this->assertNotContains(HoldSectionForContentReview::FLAG, $repaired->metadata?->toArray()['review_flags'] ?? []);
        $this->assertSame('loop_screen', $record['cleared_by'] ?? null);
    }

    private function heldSection(
        MediaProcessingLog $log,
        ServiceSectionType $type,
        int $sectionOrder,
        float $startTime,
        float $endTime,
    ): ServiceSection {
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'church_service_item_id' => null,
            'section_type' => $type->value,
            'section_order' => $sectionOrder,
            'title' => null,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $endTime - $startTime,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);

        app(HoldSectionForContentReview::class)(
            $section,
            'Song bounds contradicted by the transcript.',
            'canary-20260917-detection-retry',
            ContentHoldCheck::Boundary,
        );

        return $section->refresh();
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

    /**
     * Without the timeline, sung audio Whisper cannot hear is an empty gap the detector guesses
     * at (canary 4, run 1304), so the run is refused before the detector is paid for.
     */
    #[Test]
    public function detection_refuses_a_run_with_no_audio_timeline_before_calling_the_detector(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $detector = $this->detectorCapturingItems();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $log->forceFill(['audio_timeline_path' => null])->save();

        try {
            (new DetectServiceStructure($log))->handle(
                $detector,
                app(SilenceSnapService::class),
                app(ServiceStructureValidator::class),
                app(ServiceSectionSyncService::class),
                app(SermonCandidateConfidenceService::class),
            );
            $this->fail('Detection ran without an audio timeline.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No audio timeline recorded for this run', $exception->getMessage());
        }

        $this->assertSame([], $detector->itemsSeen);
    }

    #[Test]
    public function detection_refuses_an_unreadable_audio_timeline(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $detector = $this->detectorCapturingItems();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        Storage::disk('local')->put((string) $log->fresh()?->audio_timeline_path, '{"model": "x"}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audio timeline artifact is unreadable');

        (new DetectServiceStructure($log->fresh() ?? $log))->handle(
            $detector,
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );
    }

    #[Test]
    public function detection_hands_the_detector_the_runs_audio_timeline(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');

        $detector = new class($this->validStructure()) implements ServiceStructureInterface
        {
            public ?AudioTimeline $timelineSeen = null;

            public function __construct(private readonly ServiceStructure $structure) {}

            public function detect(ChurchServiceTranscript $transcript, array $oosItems, ?string $processingId = null, array $feedback = [], ?AudioTimeline $audioTimeline = null, ?string $model = null): ServiceStructure
            {
                $this->timelineSeen = $audioTimeline;

                return $this->structure;
            }
        };

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->storeAudioTimeline($log, [[2210, 2400, 0.9, 0.1]]);
        $this->coveringSegments($log);

        (new DetectServiceStructure($log->fresh() ?? $log))->handle(
            $detector,
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );

        $this->assertInstanceOf(AudioTimeline::class, $detector->timelineSeen);
        $this->assertEqualsWithDelta(1.0, $detector->timelineSeen->musicShare(2210.0, 2400.0), 1e-9);
    }

    #[Test]
    public function input_changed_during_the_four_draws_cannot_sync_stale_sections(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        $churchService = ChurchService::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'church_service_id' => $churchService->id,
        ]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        $detector = new class($this->validStructure(), $churchService) implements ServiceStructureInterface
        {
            private int $calls = 0;

            public function __construct(
                private readonly ServiceStructure $structure,
                private readonly ChurchService $churchService,
            ) {}

            public function detect(ChurchServiceTranscript $transcript, array $oosItems, ?string $processingId = null, array $feedback = [], ?AudioTimeline $audioTimeline = null, ?string $model = null): ServiceStructure
            {
                if ($this->calls++ === 0) {
                    ChurchServiceItem::factory()->create([
                        'church_service_id' => $this->churchService->id,
                        'source' => 'manual',
                        'title' => 'Newly attested reading',
                    ]);
                }

                return $this->structure;
            }
        };

        try {
            (new DetectServiceStructure($log))->handle(
                $detector,
                app(SilenceSnapService::class),
                app(ServiceStructureValidator::class),
                app(ServiceSectionSyncService::class),
                app(SermonCandidateConfidenceService::class),
            );
            $this->fail('Expected stale ensemble input to be refused.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('input changed while draws were running', $exception->getMessage());
        }

        $this->assertSame(0, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
        $this->assertCount(1, $log->fresh()?->processing_metadata?->toArray()['service_structure_ensemble'] ?? []);
    }

    #[Test]
    public function an_operator_section_edit_during_the_draws_is_not_overwritten(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);
        $existing = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'title' => 'Before the draws',
        ]);

        $detector = $this->detectorRunningOnce($this->validStructure(), static function () use ($existing): void {
            $existing->forceFill(['title' => 'Operator correction'])->save();
            ServiceSection::query()->whereKey($existing->id)->update(['updated_at' => now()->addMinute()]);
        });

        $this->handleDetection($log, $detector);

        $this->assertSame('Operator correction', $existing->fresh()?->title);
        $this->assertSame(1, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
        $this->assertSame(ProcessingStatus::Failed, $log->fresh()?->status);
        $this->assertStringContainsString('changed while the structure draws were running', (string) $log->fresh()?->error_message);
    }

    #[Test]
    public function cancelling_the_run_during_the_draws_prevents_sync(): void
    {
        Config::set('media-processing.service_structure.mode', 'primary');
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        $detector = $this->detectorRunningOnce($this->validStructure(), static function () use ($log): void {
            MediaProcessingLog::query()->whereKey($log->id)->update(['status' => ProcessingStatus::Cancelled->value]);
        });

        $this->handleDetection($log, $detector);

        $this->assertSame(0, ServiceSection::query()->where('media_processing_log_id', $log->id)->count());
        $this->assertSame(ProcessingStatus::Cancelled, $log->fresh()?->status);
    }

    private function detectorRunningOnce(ServiceStructure $structure, \Closure $duringFirstDraw): ServiceStructureInterface
    {
        return new class($structure, $duringFirstDraw) implements ServiceStructureInterface
        {
            private int $calls = 0;

            public function __construct(
                private readonly ServiceStructure $structure,
                private readonly \Closure $duringFirstDraw,
            ) {}

            public function detect(ChurchServiceTranscript $transcript, array $oosItems, ?string $processingId = null, array $feedback = [], ?AudioTimeline $audioTimeline = null, ?string $model = null): ServiceStructure
            {
                if ($this->calls++ === 0) {
                    ($this->duringFirstDraw)();
                }

                return $this->structure;
            }
        };
    }

    private function handleDetection(MediaProcessingLog $log, ServiceStructureInterface $detector): void
    {
        (new DetectServiceStructure($log))->handle(
            $detector,
            app(SilenceSnapService::class),
            app(ServiceStructureValidator::class),
            app(ServiceSectionSyncService::class),
            app(SermonCandidateConfidenceService::class),
        );
    }

    /**
     * @return ServiceStructureInterface&object{itemsSeen: list<list<array<string, mixed>>>}
     */
    private function detectorCapturingItems(): ServiceStructureInterface
    {
        return new class($this->validStructure()) implements ServiceStructureInterface
        {
            /** @var list<list<array<string, mixed>>> */
            public array $itemsSeen = [];

            public function __construct(private readonly ServiceStructure $structure) {}

            public function detect(ChurchServiceTranscript $transcript, array $oosItems, ?string $processingId = null, array $feedback = [], ?AudioTimeline $audioTimeline = null, ?string $model = null): ServiceStructure
            {
                $this->itemsSeen[] = $oosItems;

                return $this->structure;
            }
        };
    }

    private function runDetection(ChurchService $churchService, ServiceStructureInterface $detector): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'church_service_id' => $churchService->id,
        ]);
        $this->storeTranscript($log);
        $this->coveringSegments($log);

        (new DetectServiceStructure($log))->handle(
            $detector,
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

        $this->storeAudioTimeline($log, []);
    }

    /**
     * Every run that reaches detection has a timeline; by default one that hears neither music
     * nor speech, so it changes nothing.
     *
     * @param  list<array{0: float|int, 1: float|int, 2: float, 3: float}>  $spans
     */
    private function storeAudioTimeline(MediaProcessingLog $log, array $spans): void
    {
        $path = 'temp/audio_timeline_'.$log->processing_id.'.classes.json';
        Storage::disk('local')->put($path, AudioTimelineFixture::json($spans, 2430.0));
        $log->forceFill(['audio_timeline_path' => $path])->save();
    }

    /**
     * An RMS log for the stored transcript's 2430 s recording, sampled every 0.1 s: speech with
     * a half-second pause every 3 s throughout, except unbroken singing between the given times.
     */
    /**
     * @param  list<array{0: int, 1: int}>  $alsoSung
     */
    private function storeRmsLog(MediaProcessingLog $log, int $sungFrom, int $sungTo, array $alsoSung = []): void
    {
        $lines = [];
        $sung = [[$sungFrom, $sungTo], ...$alsoSung];

        for ($tenth = 0; $tenth < 24300; $tenth++) {
            $time = $tenth / 10;
            $isSung = array_filter($sung, static fn (array $span): bool => $time >= $span[0] && $time < $span[1]) !== [];
            $level = $isSung ? -18.0 : (fmod($time, 3.0) < 2.5 ? -25.0 : -60.0);
            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = sprintf('lavfi.astats.Overall.RMS_level=%.1f', $level);
        }

        $path = 'temp/rms_'.$log->processing_id.'.log';
        Storage::disk('local')->put($path, implode("\n", $lines)."\n");
        $log->forceFill(['rms_log_path' => $path])->save();
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

    /**
     * One prayer nested inside another — the shape run 949's first attempt failed on.
     */
    private function nestedPrayersStructure(): ServiceStructure
    {
        return ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('prayer', 130.0, 400.0),
            $this->section('prayer', 150.0, 380.0),
            $this->section('bible_reading', 420.0, 590.0),
            $this->section('sermon', 600.0, 2200.0),
            $this->section('song', 2210.0, 2400.0),
        ], model: 'mock');
    }

    private function section(
        string $type,
        float $start,
        float $end,
        float $confidence = 0.95,
        ?int $oosItemId = null,
        ?string $summary = null,
        ?string $sermonReference = null,
    ): ServiceStructureSection {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => $confidence,
            'oos_item_id' => $oosItemId,
            'summary' => $summary,
            'sermon_reference' => $sermonReference,
        ]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }

    private function referencedSection(
        string $type,
        float $start,
        float $end,
        ?string $readingReference = null,
        ?string $sermonReference = null,
    ): ServiceStructureSection {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => 0.95,
            'reading_reference' => $readingReference,
            'sermon_reference' => $sermonReference,
        ]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }
}
