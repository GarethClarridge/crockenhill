<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Enums\ServiceSectionType;
use App\Jobs\ClassifySpeechSections;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\SpeechSectionClassificationService;
use App\Services\Song\SongTitleHintExtractor;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClassifySpeechSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media-processing.section_classification.classify_speech_sections' => true,
            'media-processing.section_classification.short_song_max_duration_seconds' => 90,
        ]);
    }

    #[Test]
    public function it_targets_the_existing_audio_processing_queue(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->make();

        $job = new ClassifySpeechSections($processingLog);

        $this->assertSame('audio-processing', $job->queue);
    }

    #[Test]
    public function it_relabels_a_single_transcribed_speech_section_from_ai_output(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 60.0,
            'end_time' => 180.0,
            'duration' => 120.0,
            'metadata' => [
                'transcript' => 'Let us pray together.',
                'confidence_level' => 'low',
            ],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Prayer->value,
                    'title' => null,
                    'start_time' => 60.0,
                    'end_time' => 180.0,
                    'duration' => 120.0,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.91,
                        'transcript' => 'Let us pray together.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $section->refresh();
        $processingLog->refresh();

        $this->assertSame(ServiceSectionType::Prayer, $section->section_type);
        $this->assertFalse($section->needs_manual_review);
        $this->assertSame('ai_transcript', $section->metadata['confidence_source'] ?? null);
        $this->assertSame('classify_speech_sections', $processingLog->current_step);
        $this->assertDatabaseHas('sermon_processing_steps', [
            'processing_id' => $processingLog->processing_id,
            'step' => ChurchServiceProcessingTimeline::CLASSIFY_SPEECH_SECTIONS,
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function it_splits_a_transcribed_speech_section_into_multiple_sections(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 60.0,
            'end_time' => 180.0,
            'duration' => 120.0,
            'metadata' => [
                'transcript' => 'Welcome. Let us pray.',
                'confidence_level' => 'low',
            ],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [
                    [
                        'section_type' => ServiceSectionType::Welcome->value,
                        'title' => null,
                        'start_time' => 60.0,
                        'end_time' => 90.0,
                        'duration' => 30.0,
                        'needs_manual_review' => false,
                        'metadata' => [
                            'confidence_level' => 'high',
                            'classification_mode' => 'ai_transcript',
                            'confidence_source' => 'ai_transcript',
                            'confidence_score' => 0.9,
                            'transcript' => 'Welcome. Let us pray.',
                        ],
                    ],
                    [
                        'section_type' => ServiceSectionType::Prayer->value,
                        'title' => null,
                        'start_time' => 90.0,
                        'end_time' => 180.0,
                        'duration' => 90.0,
                        'needs_manual_review' => false,
                        'metadata' => [
                            'confidence_level' => 'high',
                            'classification_mode' => 'ai_transcript',
                            'confidence_source' => 'ai_transcript',
                            'confidence_score' => 0.88,
                            'transcript' => 'Welcome. Let us pray.',
                        ],
                    ],
                ];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(2, $sections);
        $this->assertSame(ServiceSectionType::Welcome, $sections[0]->section_type);
        $this->assertSame(ServiceSectionType::Prayer, $sections[1]->section_type);
        $this->assertSame(1, $sections[0]->section_order);
        $this->assertSame(2, $sections[1]->section_order);
    }

    #[Test]
    public function it_marks_a_moderate_confidence_transcript_sermon_for_manual_review(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 60.0,
            'end_time' => 300.0,
            'duration' => 240.0,
            'metadata' => [
                'transcript' => 'Turn in your Bibles with me.',
            ],
        ]);

        // Classifier was not high-confidence (review flagged, score below the 0.85 bar).
        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Sermon->value,
                    'title' => null,
                    'start_time' => 60.0,
                    'end_time' => 300.0,
                    'duration' => 240.0,
                    'needs_manual_review' => true,
                    'metadata' => [
                        'confidence_level' => 'low',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.7,
                        'transcript' => 'Turn in your Bibles with me.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $section = ServiceSection::query()->firstOrFail();

        $this->assertSame(ServiceSectionType::Sermon, $section->section_type);
        $this->assertTrue($section->needs_manual_review);
        $this->assertSame('secondary_sermon_candidate', $section->metadata['review_reason'] ?? null);
    }

    #[Test]
    public function it_does_not_flag_a_high_confidence_transcript_sermon_when_no_conflicting_primary_exists(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 2420.0,
            'end_time' => 4180.0,
            'duration' => 1760.0,
            'metadata' => [
                'transcript' => 'Turn in your Bibles with me to Ezekiel chapter one.',
            ],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Sermon->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.92,
                        'transcript' => 'Turn in your Bibles with me to Ezekiel chapter one.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $section = ServiceSection::query()->firstOrFail();

        $this->assertSame(ServiceSectionType::Sermon, $section->section_type);
        $this->assertFalse($section->needs_manual_review);
        $this->assertArrayNotHasKey('review_reason', $section->metadata->toArray());
        $this->assertGreaterThanOrEqual(0.85, (float) $section->confidence);
    }

    #[Test]
    public function it_flags_a_high_confidence_transcript_sermon_when_a_conflicting_rms_sermon_segment_exists(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // The segmentation pipeline already flagged a livestream segment as the sermon.
        $processingLog->segments()->create([
            'segment_index' => 0,
            'start_time' => 2500.0,
            'end_time' => 4300.0,
            'duration' => 1800.0,
            'classification' => 'speech',
            'is_sermon_candidate' => true,
            'is_sermon_segment' => true,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 60.0,
            'end_time' => 300.0,
            'duration' => 240.0,
            'metadata' => [
                'transcript' => 'Turn in your Bibles with me.',
            ],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Sermon->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.94,
                        'transcript' => 'Turn in your Bibles with me.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $section = ServiceSection::query()->where('start_time', 60.0)->firstOrFail();

        $this->assertSame(ServiceSectionType::Sermon, $section->section_type);
        $this->assertTrue($section->needs_manual_review);
        $this->assertSame('secondary_sermon_candidate', $section->metadata['review_reason'] ?? null);
    }

    #[Test]
    public function it_folds_short_mid_sermon_songs_into_a_single_sermon_section(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 600.0,
            'end_time' => 1200.0,
            'duration' => 600.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'audio_only',
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 1200.0,
            'end_time' => 1260.0,
            'duration' => 60.0,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'start_time' => 1260.0,
            'end_time' => 1680.0,
            'duration' => 420.0,
            'metadata' => [
                'transcript' => 'Continuing the sermon after the hymn.',
                'confidence_level' => 'low',
            ],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Sermon->value,
                    'title' => null,
                    'start_time' => 1260.0,
                    'end_time' => 1680.0,
                    'duration' => 420.0,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'Continuing the sermon after the hymn.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(1, $sections);
        $this->assertSame(ServiceSectionType::Sermon, $sections[0]->section_type);
        $this->assertSame(600.0, $sections[0]->start_time);
        $this->assertSame(1680.0, $sections[0]->end_time);
        $this->assertFalse($sections[0]->needs_manual_review);
        $this->assertEquals(60.0, $sections[0]->metadata['folded_song_duration_seconds'] ?? null);
    }

    #[Test]
    public function it_folds_multiple_short_song_interruptions_into_a_single_sermon_section(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 600.0,
            'end_time' => 1200.0,
            'duration' => 600.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'audio_only',
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 1200.0,
            'end_time' => 1260.0,
            'duration' => 60.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'start_time' => 1260.0,
            'end_time' => 1500.0,
            'duration' => 240.0,
            'metadata' => [
                'transcript' => 'Continuing sermon section one.',
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 4,
            'start_time' => 1500.0,
            'end_time' => 1560.0,
            'duration' => 60.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 5,
            'start_time' => 1560.0,
            'end_time' => 1800.0,
            'duration' => 240.0,
            'metadata' => [
                'transcript' => 'Continuing sermon section two.',
            ],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Sermon->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'Excerpt',
                        'transcript_scope' => 'section_excerpt',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(1, $sections);
        $this->assertSame(ServiceSectionType::Sermon, $sections[0]->section_type);
        $this->assertSame(600.0, $sections[0]->start_time);
        $this->assertSame(1800.0, $sections[0]->end_time);
        $this->assertEquals(120.0, $sections[0]->metadata['folded_song_duration_seconds'] ?? null);
    }

    #[Test]
    public function it_continues_processing_other_sections_when_one_ai_classification_fails(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        $firstSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 60.0,
            'end_time' => 120.0,
            'duration' => 60.0,
            'metadata' => [
                'transcript' => 'First transcript.',
            ],
        ]);

        $secondSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 120.0,
            'end_time' => 240.0,
            'duration' => 120.0,
            'metadata' => [
                'transcript' => 'Second transcript.',
            ],
        ]);

        $service = new class($firstSection->id) extends SpeechSectionClassificationService
        {
            public function __construct(
                private readonly int $failingSectionId
            ) {}

            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                if ($section->id === $this->failingSectionId) {
                    throw new \RuntimeException('Classifier failure');
                }

                return [[
                    'section_type' => ServiceSectionType::Prayer->value,
                    'title' => null,
                    'start_time' => 120.0,
                    'end_time' => 240.0,
                    'duration' => 120.0,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'Second transcript.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(2, $sections);
        $this->assertSame(ServiceSectionType::Other, $sections[0]->section_type);
        $this->assertTrue($sections[0]->needs_manual_review);
        $this->assertSame('speech_section_classification_failed', $sections[0]->metadata['review_reason'] ?? null);
        $this->assertSame(ServiceSectionType::Prayer, $sections[1]->section_type);
        $this->assertFalse($sections[1]->needs_manual_review);
    }

    #[Test]
    public function it_demotes_a_short_secondary_sermon_to_childrens_talk_after_classification(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // RMS-detected main sermon (30 min) — skipped by shouldClassify()
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 2500.0,
            'end_time' => 4300.0,
            'duration' => 1800.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        // Speech section classified by AI as sermon (8 min — short enough to be children's talk)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 860.0,
            'end_time' => 1340.0,
            'duration' => 480.0,
            'metadata' => ['transcript' => 'The story of Esther. Can anybody tell me who Esther was?'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Sermon->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.88,
                        'transcript' => 'The story of Esther.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(2, $sections);

        $mainSermon = $sections->first(fn ($s) => $s->start_time == 2500.0);
        $demoted = $sections->first(fn ($s) => $s->start_time == 860.0);

        $this->assertNotNull($mainSermon);
        $this->assertSame(ServiceSectionType::Sermon, $mainSermon->section_type);

        $this->assertNotNull($demoted);
        $this->assertSame(ServiceSectionType::ChildrensTalk, $demoted->section_type);
        $this->assertFalse($demoted->needs_manual_review);
        $this->assertSame('high', $demoted->metadata['confidence_level'] ?? null);
        $this->assertSame('demoted_secondary_sermon_to_childrens_talk', $demoted->metadata['review_reason'] ?? null);
        $this->assertContains('heuristic_demotion', $demoted->metadata['review_flags'] ?? []);
        $this->assertSame(ServiceSectionType::Sermon->value, $demoted->metadata['original_ai_classification'] ?? null);
    }

    #[Test]
    public function it_flags_but_does_not_demote_a_long_secondary_sermon(): void
    {
        config(['media-processing.section_classification.childrens_talk_max_duration_seconds' => 900]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // RMS-detected main sermon (45 min)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 3000.0,
            'end_time' => 5700.0,
            'duration' => 2700.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        // Speech section classified by AI as sermon (20 min — above the 15-min threshold)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 600.0,
            'end_time' => 1800.0,
            'duration' => 1200.0,
            'metadata' => ['transcript' => 'Turn in your Bibles to Genesis.'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Sermon->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'Turn in your Bibles to Genesis.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $longSecondary = $sections->first(fn ($s) => $s->start_time == 600.0);

        $this->assertNotNull($longSecondary);
        $this->assertSame(ServiceSectionType::Sermon, $longSecondary->section_type);
        $this->assertTrue($longSecondary->needs_manual_review);
        $this->assertSame('multiple_sermons_detected', $longSecondary->metadata['review_reason'] ?? null);
    }

    #[Test]
    public function it_folds_before_demoting_so_sermon_song_sermon_clusters_are_not_incorrectly_demoted(): void
    {
        config(['media-processing.section_classification.childrens_talk_max_duration_seconds' => 900]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // RMS-detected first sermon segment (20 min)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 600.0,
            'end_time' => 1800.0,
            'duration' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high', 'classification_mode' => 'audio_only'],
        ]);

        // Short song mid-sermon (1 min) — should be folded into the sermon above
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 1800.0,
            'end_time' => 1860.0,
            'duration' => 60.0,
            'metadata' => ['confidence_level' => 'low', 'classification_mode' => 'audio_only'],
        ]);

        // Continuation of sermon after song — classified as SERMON by AI (7 min)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'start_time' => 1860.0,
            'end_time' => 2280.0,
            'duration' => 420.0,
            'metadata' => ['transcript' => 'Continuing the sermon after the illustration.'],
        ]);

        // Short separate children's talk — AI classifies as SERMON (8 min)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 4,
            'start_time' => 200.0,
            'end_time' => 680.0,
            'duration' => 480.0,
            'metadata' => ['transcript' => 'Good morning boys and girls! Can anybody tell me what this is?'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Sermon->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'Excerpt.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('start_time')
            ->get();

        // After fold: sermon(600–2280) merged. After demote: short 480s section → childrens_talk.
        $this->assertCount(2, $sections);

        $mainSermon = $sections->first(fn ($s) => $s->start_time == 600.0);
        $demoted = $sections->first(fn ($s) => $s->start_time == 200.0);

        $this->assertNotNull($mainSermon);
        $this->assertSame(ServiceSectionType::Sermon, $mainSermon->section_type);
        $this->assertSame(2280.0, $mainSermon->end_time);
        $this->assertFalse($mainSermon->needs_manual_review);

        $this->assertNotNull($demoted);
        $this->assertSame(ServiceSectionType::ChildrensTalk, $demoted->section_type);
        $this->assertFalse($demoted->needs_manual_review);
        $this->assertSame('high', $demoted->metadata['confidence_level'] ?? null);
        $this->assertSame('demoted_secondary_sermon_to_childrens_talk', $demoted->metadata['review_reason'] ?? null);
        $this->assertContains('heuristic_demotion', $demoted->metadata['review_flags'] ?? []);
    }

    #[Test]
    public function it_writes_song_title_hint_into_following_audio_only_song_section(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // Speech section that will be classified as a song announcement.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'duration' => 60.0,
            'metadata' => [
                'transcript' => 'We are going to sing Your Word.',
                'confidence_level' => 'low',
            ],
        ]);

        // RMS-detected audio-only song that follows the announcement.
        $audioSong = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 60.0,
            'end_time' => 270.0,
            'duration' => 210.0,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'confidence_level' => 'high',
            ],
        ]);

        // Mock classifier: turns the speech section into an ai_transcript SONG section.
        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Song->value,
                    'title' => null,
                    'start_time' => 0.0,
                    'end_time' => 60.0,
                    'duration' => 60.0,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'We are going to sing Your Word.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $audioSong->refresh();

        $this->assertSame('Your Word', $audioSong->metadata['song_title_hint'] ?? null);
    }

    #[Test]
    public function it_merges_a_short_song_section_into_the_following_song_section(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        $shortSong = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 100.0,
            'end_time' => 107.0,
            'duration' => 7.0,
            'metadata' => ['classification_mode' => 'audio_only'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 107.0,
            'end_time' => 231.0,
            'duration' => 124.0,
            'metadata' => ['classification_mode' => 'audio_only'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(1, $sections);
        $this->assertSame(ServiceSectionType::Song, $sections[0]->section_type);
        $this->assertSame(100.0, $sections[0]->start_time);
        $this->assertSame(231.0, $sections[0]->end_time);
    }

    #[Test]
    public function it_merges_a_short_speech_section_into_the_following_same_type_section(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // 15s Bible Reading (announcement classified as reading)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 200.0,
            'end_time' => 215.0,
            'duration' => 15.0,
            'metadata' => ['transcript' => 'I will ask Nemi to come and do the Bible reading.'],
        ]);

        // 2m 58s Bible Reading (actual reading)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 215.0,
            'end_time' => 393.0,
            'duration' => 178.0,
            'metadata' => ['transcript' => 'In the beginning God created the heavens and the earth.'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            private int $callCount = 0;

            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                $this->callCount++;

                return [[
                    'section_type' => ServiceSectionType::BibleReading->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'excerpt',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(1, $sections);
        $this->assertSame(ServiceSectionType::BibleReading, $sections[0]->section_type);
        $this->assertSame(200.0, $sections[0]->start_time);
        $this->assertSame(393.0, $sections[0]->end_time);
    }

    #[Test]
    public function it_always_merges_adjacent_childrens_talk_sections_regardless_of_duration(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // 5m 24s Children's Talk
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 300.0,
            'end_time' => 624.0,
            'duration' => 324.0,
            'metadata' => ['transcript' => 'Good morning everyone, who can tell me what this picture is of?'],
        ]);

        // 3m 2s Children's Talk
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 624.0,
            'end_time' => 806.0,
            'duration' => 182.0,
            'metadata' => ['transcript' => 'So what does that teach us? Let us pray together.'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::ChildrensTalk->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.88,
                        'transcript' => 'excerpt',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(1, $sections);
        $this->assertSame(ServiceSectionType::ChildrensTalk, $sections[0]->section_type);
        $this->assertSame(300.0, $sections[0]->start_time);
        $this->assertSame(806.0, $sections[0]->end_time);
    }

    #[Test]
    public function it_chains_merges_across_three_adjacent_same_type_sections(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        foreach ([
            [1, 0.0, 10.0, 10.0],
            [2, 10.0, 30.0, 20.0],
            [3, 30.0, 210.0, 180.0],
        ] as [$order, $start, $end, $dur]) {
            ServiceSection::factory()->create([
                'media_processing_log_id' => $processingLog->id,
                'section_type' => ServiceSectionType::Other->value,
                'section_order' => $order,
                'start_time' => $start,
                'end_time' => $end,
                'duration' => $dur,
                'metadata' => ['transcript' => 'Good morning children.'],
            ]);
        }

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::ChildrensTalk->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'Good morning children.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->get();

        $this->assertCount(1, $sections);
        $this->assertSame(0.0, $sections[0]->start_time);
        $this->assertSame(210.0, $sections[0]->end_time);
    }

    #[Test]
    public function it_does_not_merge_two_substantial_adjacent_songs(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 150.0,
            'duration' => 150.0,
            'metadata' => ['classification_mode' => 'audio_only'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 150.0,
            'end_time' => 360.0,
            'duration' => 210.0,
            'metadata' => ['classification_mode' => 'audio_only'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->get();

        $this->assertCount(2, $sections);
    }

    #[Test]
    public function it_does_not_merge_adjacent_sections_of_different_types(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 15.0,
            'duration' => 15.0,
            'metadata' => ['transcript' => 'Let us pray.'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 15.0,
            'end_time' => 15.0 + 15.0,
            'duration' => 15.0,
            'metadata' => ['transcript' => 'Hear O Israel.'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            private int $call = 0;

            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                $this->call++;
                $type = $this->call === 1
                    ? ServiceSectionType::Prayer->value
                    : ServiceSectionType::BibleReading->value;

                return [[
                    'section_type' => $type,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'excerpt',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(2, $sections);
        $this->assertSame(ServiceSectionType::Prayer, $sections[0]->section_type);
        $this->assertSame(ServiceSectionType::BibleReading, $sections[1]->section_type);
    }

    #[Test]
    public function it_keeps_the_longer_sections_metadata_and_confidence_as_primary(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // 10s short section — will be secondary after merge
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 10.0,
            'duration' => 10.0,
            'metadata' => ['transcript' => 'Short intro.'],
        ]);

        // 180s long section — will be primary after merge
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 10.0,
            'end_time' => 190.0,
            'duration' => 180.0,
            'metadata' => ['transcript' => 'Longer content.'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            private int $call = 0;

            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                $this->call++;
                $score = $this->call === 1 ? 0.95 : 0.55;

                return [[
                    'section_type' => ServiceSectionType::Prayer->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => $score,
                        'transcript' => 'excerpt',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->get();

        $this->assertCount(1, $sections);
        // Primary is the 180s section (lower confidence score 0.55)
        $this->assertEqualsWithDelta(0.55, $sections[0]->confidence, 0.01);
        $this->assertSame(0.0, $sections[0]->start_time);
        $this->assertSame(190.0, $sections[0]->end_time);
    }

    #[Test]
    public function it_ors_needs_manual_review_from_both_sides(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 180.0,
            'duration' => 180.0,
            'metadata' => ['transcript' => 'Good morning children.'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 180.0,
            'end_time' => 240.0,
            'duration' => 60.0,
            'metadata' => [
                'transcript' => 'Short continuation.',
                'review_reason' => 'low_confidence',
            ],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            private int $call = 0;

            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                $this->call++;
                $review = $this->call === 2;

                return [[
                    'section_type' => ServiceSectionType::ChildrensTalk->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => $review,
                    'metadata' => [
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'excerpt',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->get();

        $this->assertCount(1, $sections);
        $this->assertTrue($sections[0]->needs_manual_review);
    }

    #[Test]
    public function it_preserves_review_reason_from_secondary_in_merged_metadata(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 180.0,
            'duration' => 180.0,
            'metadata' => ['transcript' => 'Main content.'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 180.0,
            'end_time' => 240.0,
            'duration' => 60.0,
            'metadata' => [
                'transcript' => 'Short part.',
                'review_reason' => 'oos_mismatch',
            ],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            private int $call = 0;

            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                $this->call++;
                $hasReview = $this->call === 2;

                return [[
                    'section_type' => ServiceSectionType::ChildrensTalk->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => $hasReview,
                    'metadata' => array_filter([
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'excerpt',
                        'review_reason' => $hasReview ? 'oos_mismatch' : null,
                    ]),
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->get();

        $this->assertCount(1, $sections);
        $this->assertSame('oos_mismatch', $sections[0]->metadata['merged_review_reason'] ?? null);
    }

    #[Test]
    public function it_does_not_merge_sections_with_a_gap_above_the_threshold(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 15.0,
            'duration' => 15.0,
            'metadata' => ['transcript' => 'Short reading intro.'],
        ]);

        // 5-second gap between sections
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 20.0,
            'end_time' => 198.0,
            'duration' => 178.0,
            'metadata' => ['transcript' => 'In the beginning God created the heavens.'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::BibleReading->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'excerpt',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(2, $sections);
    }

    #[Test]
    public function it_merges_sections_within_the_allowed_gap_tolerance(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 15.0,
            'duration' => 15.0,
            'metadata' => ['transcript' => 'Short intro.'],
        ]);

        // 1-second gap — within tolerance
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 2,
            'start_time' => 16.0,
            'end_time' => 196.0,
            'duration' => 180.0,
            'metadata' => ['transcript' => 'Main reading.'],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::BibleReading->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'excerpt',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->get();

        $this->assertCount(1, $sections);
        $this->assertSame(0.0, $sections[0]->start_time);
        $this->assertSame(196.0, $sections[0]->end_time);
    }

    #[Test]
    public function it_preserves_song_title_hint_when_short_song_announcement_is_merged(): void
    {
        config([
            'media-processing.section_classification.adjacent_merge_min_duration_seconds' => 30,
            'media-processing.section_classification.adjacent_merge_max_gap_seconds' => 2,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // Short ai_transcript Song (announcement) — song title hint will be written into the audio-only section
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 20.0,
            'duration' => 20.0,
            'metadata' => ['transcript' => 'We are going to sing Amazing Grace.'],
        ]);

        // Longer audio-only Song — song_title_hint gets written here by SongTitleHintExtractor before merging
        $audioSong = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 20.0,
            'end_time' => 200.0,
            'duration' => 180.0,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'confidence_level' => 'high',
            ],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Song->value,
                    'title' => null,
                    'start_time' => (float) $section->start_time,
                    'end_time' => (float) $section->end_time,
                    'duration' => (float) $section->duration,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'We are going to sing Amazing Grace.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->get();

        $this->assertCount(1, $sections);
        $this->assertSame(ServiceSectionType::Song, $sections[0]->section_type);
        $this->assertSame(0.0, $sections[0]->start_time);
        $this->assertSame(200.0, $sections[0]->end_time);
        // The hint was written into the audio_only section before merging, and the
        // audio_only section (180s) is primary — its metadata carries the hint
        $this->assertSame('Amazing Grace', $sections[0]->metadata['song_title_hint'] ?? null);
    }

    #[Test]
    public function it_does_not_write_song_title_hint_when_announcement_has_no_extractable_title(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 1,
            'start_time' => 0.0,
            'end_time' => 60.0,
            'duration' => 60.0,
            'metadata' => [
                'transcript' => 'Please stand.',
                'confidence_level' => 'low',
            ],
        ]);

        $audioSong = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 60.0,
            'end_time' => 270.0,
            'duration' => 210.0,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'confidence_level' => 'high',
            ],
        ]);

        $service = new class extends SpeechSectionClassificationService
        {
            public function classify(ServiceSection $section, array $serviceContext = []): array
            {
                return [[
                    'section_type' => ServiceSectionType::Song->value,
                    'title' => null,
                    'start_time' => 0.0,
                    'end_time' => 60.0,
                    'duration' => 60.0,
                    'needs_manual_review' => false,
                    'metadata' => [
                        'confidence_level' => 'high',
                        'classification_mode' => 'ai_transcript',
                        'confidence_source' => 'ai_transcript',
                        'confidence_score' => 0.9,
                        'transcript' => 'Please stand.',
                    ],
                ]];
            }
        };

        $job = new ClassifySpeechSections($processingLog);
        $job->handle($service, app(ServiceSectionSyncService::class), app(SongTitleHintExtractor::class));

        $audioSong->refresh();

        $this->assertArrayNotHasKey('song_title_hint', $audioSong->metadata->toArray());
    }
}
