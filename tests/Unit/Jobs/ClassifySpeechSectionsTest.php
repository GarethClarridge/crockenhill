<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\ServiceSectionType;
use App\Jobs\ClassifySpeechSections;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ServiceSectionSyncService;
use App\Services\SongTitleHintExtractor;
use App\Services\SpeechSectionClassificationService;
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
            'section_type' => ServiceSectionType::OTHER->value,
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
                    'section_type' => ServiceSectionType::PRAYER->value,
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

        $this->assertSame(ServiceSectionType::PRAYER, $section->section_type);
        $this->assertFalse($section->needs_manual_review);
        $this->assertSame('ai_transcript', $section->metadata['confidence_source'] ?? null);
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
            'section_type' => ServiceSectionType::OTHER->value,
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
                        'section_type' => ServiceSectionType::WELCOME->value,
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
                        'section_type' => ServiceSectionType::PRAYER->value,
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
        $this->assertSame(ServiceSectionType::WELCOME, $sections[0]->section_type);
        $this->assertSame(ServiceSectionType::PRAYER, $sections[1]->section_type);
        $this->assertSame(1, $sections[0]->section_order);
        $this->assertSame(2, $sections[1]->section_order);
    }

    #[Test]
    public function it_marks_secondary_sermon_candidates_for_manual_review(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
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
                    'section_type' => ServiceSectionType::SERMON->value,
                    'title' => null,
                    'start_time' => 60.0,
                    'end_time' => 300.0,
                    'duration' => 240.0,
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

        $section = ServiceSection::query()->firstOrFail();

        $this->assertSame(ServiceSectionType::SERMON, $section->section_type);
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
            'section_type' => ServiceSectionType::SERMON->value,
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
            'section_type' => ServiceSectionType::SONG->value,
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
            'section_type' => ServiceSectionType::OTHER->value,
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
                    'section_type' => ServiceSectionType::SERMON->value,
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
        $this->assertSame(ServiceSectionType::SERMON, $sections[0]->section_type);
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
            'section_type' => ServiceSectionType::SERMON->value,
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
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 2,
            'start_time' => 1200.0,
            'end_time' => 1260.0,
            'duration' => 60.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
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
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 4,
            'start_time' => 1500.0,
            'end_time' => 1560.0,
            'duration' => 60.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
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
                    'section_type' => ServiceSectionType::SERMON->value,
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
        $this->assertSame(ServiceSectionType::SERMON, $sections[0]->section_type);
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
            'section_type' => ServiceSectionType::OTHER->value,
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
            'section_type' => ServiceSectionType::OTHER->value,
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
                    'section_type' => ServiceSectionType::PRAYER->value,
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
        $this->assertSame(ServiceSectionType::OTHER, $sections[0]->section_type);
        $this->assertTrue($sections[0]->needs_manual_review);
        $this->assertSame('speech_section_classification_failed', $sections[0]->metadata['review_reason'] ?? null);
        $this->assertSame(ServiceSectionType::PRAYER, $sections[1]->section_type);
        $this->assertFalse($sections[1]->needs_manual_review);
    }

    #[Test]
    public function it_demotes_a_short_secondary_sermon_to_childrens_talk_after_classification(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // RMS-detected main sermon (30 min) — skipped by shouldClassify()
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SERMON->value,
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
            'section_type' => ServiceSectionType::OTHER->value,
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
                    'section_type' => ServiceSectionType::SERMON->value,
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
        $this->assertSame(ServiceSectionType::SERMON, $mainSermon->section_type);

        $this->assertNotNull($demoted);
        $this->assertSame(ServiceSectionType::CHILDRENS_TALK, $demoted->section_type);
        $this->assertTrue($demoted->needs_manual_review);
        $this->assertSame('demoted_secondary_sermon_to_childrens_talk', $demoted->metadata['review_reason'] ?? null);
        $this->assertSame(ServiceSectionType::SERMON->value, $demoted->metadata['original_ai_classification'] ?? null);
    }

    #[Test]
    public function it_flags_but_does_not_demote_a_long_secondary_sermon(): void
    {
        config(['media-processing.section_classification.childrens_talk_max_duration_seconds' => 900]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // RMS-detected main sermon (45 min)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::SERMON->value,
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
            'section_type' => ServiceSectionType::OTHER->value,
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
                    'section_type' => ServiceSectionType::SERMON->value,
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
        $this->assertSame(ServiceSectionType::SERMON, $longSecondary->section_type);
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
            'section_type' => ServiceSectionType::SERMON->value,
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
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 2,
            'start_time' => 1800.0,
            'end_time' => 1860.0,
            'duration' => 60.0,
            'metadata' => ['confidence_level' => 'low', 'classification_mode' => 'audio_only'],
        ]);

        // Continuation of sermon after song — classified as SERMON by AI (7 min)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 3,
            'start_time' => 1860.0,
            'end_time' => 2280.0,
            'duration' => 420.0,
            'metadata' => ['transcript' => 'Continuing the sermon after the illustration.'],
        ]);

        // Short separate children's talk — AI classifies as SERMON (8 min)
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::OTHER->value,
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
                    'section_type' => ServiceSectionType::SERMON->value,
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
        $this->assertSame(ServiceSectionType::SERMON, $mainSermon->section_type);
        $this->assertSame(2280.0, $mainSermon->end_time);
        $this->assertFalse($mainSermon->needs_manual_review);

        $this->assertNotNull($demoted);
        $this->assertSame(ServiceSectionType::CHILDRENS_TALK, $demoted->section_type);
        $this->assertTrue($demoted->needs_manual_review);
        $this->assertSame('demoted_secondary_sermon_to_childrens_talk', $demoted->metadata['review_reason'] ?? null);
    }

    #[Test]
    public function it_writes_song_title_hint_into_following_audio_only_song_section(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        // Speech section that will be classified as a song announcement.
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::OTHER->value,
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
            'section_type' => ServiceSectionType::SONG->value,
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
                    'section_type' => ServiceSectionType::SONG->value,
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
    public function it_does_not_write_song_title_hint_when_announcement_has_no_extractable_title(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::OTHER->value,
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
            'section_type' => ServiceSectionType::SONG->value,
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
                    'section_type' => ServiceSectionType::SONG->value,
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
