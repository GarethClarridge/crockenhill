<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Contracts\TranscriptionServiceInterface;
use App\Enums\SermonService;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\ClassifyServiceSections;
use App\Jobs\TranscribeSpeechSegments;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ServiceSectionClassifier;
use App\Services\ServiceSectionSyncService;
use App\Services\StorageAdapterHelper;
use App\Services\VideoExtractionService;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscribeSpeechSegmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.section_classification.transcribe_speech_segments' => true,
            'media-processing.section_classification.speech_transcription_min_duration_seconds' => 10,
        ]);
    }

    #[Test]
    public function it_transcribes_eligible_speech_sections_and_stores_the_transcript_in_metadata(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'temp/source-video.mp4',
            'processing_id' => 'speech-proc-123',
        ]);

        Storage::disk('local')->put('temp/source-video.mp4', 'video');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'start_time' => 30.0,
            'end_time' => 95.0,
            'duration' => 65.0,
            'status' => ServiceSectionStatus::IDENTIFIED->value,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        Storage::disk('public')->put('sermons/audio/section-1.mp3', 'audio');

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->with(
                $this->callback(fn (string $path): bool => str_ends_with($path, 'source-video.mp4')),
                $this->callback(function (object $segment): bool {
                    return (float) $segment->start_time === 30.0
                        && (float) $segment->end_time === 95.0;
                }),
                'speech-proc-123_section_'.$section->id.'_speech.mp3'
            )
            ->willReturn([
                'audio_path' => 'sermons/audio/section-1.mp3',
                'full_path' => Storage::disk('public')->path('sermons/audio/section-1.mp3'),
                'original_size' => 1024,
                'final_size' => 1024,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $transcriptionService = $this->createMock(TranscriptionServiceInterface::class);
        $transcriptionService->expects($this->once())
            ->method('transcribe')
            ->with(
                'sermons/audio/section-1.mp3',
                'speech-proc-123-section-'.$section->id,
                'public'
            )
            ->willReturn('Segment transcript');

        $job = new TranscribeSpeechSegments($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class), $transcriptionService);

        $section->refresh();

        $this->assertSame('Segment transcript', $section->metadata['transcript'] ?? null);
        $this->assertDatabaseHas('sermon_processing_steps', [
            'processing_id' => $processingLog->processing_id,
            'step' => ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS,
            'status' => 'completed',
        ]);
        Storage::disk('public')->assertMissing('sermons/audio/section-1.mp3');
    }

    #[Test]
    public function it_skips_sections_shorter_than_the_configured_minimum_duration(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'temp/source-video.mp4',
        ]);

        Storage::disk('local')->put('temp/source-video.mp4', 'video');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'duration' => 5.0,
            'start_time' => 10.0,
            'end_time' => 15.0,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractOptimizedAudio');

        $transcriptionService = $this->createMock(TranscriptionServiceInterface::class);
        $transcriptionService->expects($this->never())->method('transcribe');

        $job = new TranscribeSpeechSegments($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class), $transcriptionService);

        $section->refresh();

        $this->assertArrayNotHasKey('transcript', $section->metadata ?? []);
        $this->assertDatabaseHas('sermon_processing_steps', [
            'processing_id' => $processingLog->processing_id,
            'step' => ChurchServiceProcessingTimeline::TRANSCRIBE_SPEECH_SEGMENTS,
            'status' => 'skipped',
        ]);
    }

    #[Test]
    public function it_skips_sections_that_already_have_a_transcript(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'temp/source-video.mp4',
        ]);

        Storage::disk('local')->put('temp/source-video.mp4', 'video');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'duration' => 60.0,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
                'transcript' => 'Existing transcript',
            ],
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractOptimizedAudio');

        $transcriptionService = $this->createMock(TranscriptionServiceInterface::class);
        $transcriptionService->expects($this->never())->method('transcribe');

        $job = new TranscribeSpeechSegments($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class), $transcriptionService);

        $section->refresh();

        $this->assertSame('Existing transcript', $section->metadata['transcript'] ?? null);
    }

    #[Test]
    public function it_skips_song_and_sermon_sections(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'temp/source-video.mp4',
        ]);

        Storage::disk('local')->put('temp/source-video.mp4', 'video');

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SONG->value,
            'section_order' => 1,
            'duration' => 90.0,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::SERMON->value,
            'section_order' => 2,
            'duration' => 1800.0,
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractOptimizedAudio');

        $transcriptionService = $this->createMock(TranscriptionServiceInterface::class);
        $transcriptionService->expects($this->never())->method('transcribe');

        $job = new TranscribeSpeechSegments($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class), $transcriptionService);
    }

    #[Test]
    public function it_continues_transcribing_later_sections_when_an_earlier_section_fails(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'temp/source-video.mp4',
            'processing_id' => 'speech-failure-proc',
        ]);

        Storage::disk('local')->put('temp/source-video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-1.mp3', 'audio-1');
        Storage::disk('public')->put('sermons/audio/section-2.mp3', 'audio-2');

        $firstSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'start_time' => 10.0,
            'end_time' => 80.0,
            'duration' => 70.0,
        ]);

        $secondSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 2,
            'start_time' => 90.0,
            'end_time' => 170.0,
            'duration' => 80.0,
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->exactly(2))
            ->method('extractOptimizedAudio')
            ->willReturnOnConsecutiveCalls(
                [
                    'audio_path' => 'sermons/audio/section-1.mp3',
                    'full_path' => Storage::disk('public')->path('sermons/audio/section-1.mp3'),
                    'original_size' => 1024,
                    'final_size' => 1024,
                    'compression_applied' => false,
                    'compression_ratio' => 1.0,
                    'valid_for_transcription' => true,
                ],
                [
                    'audio_path' => 'sermons/audio/section-2.mp3',
                    'full_path' => Storage::disk('public')->path('sermons/audio/section-2.mp3'),
                    'original_size' => 1024,
                    'final_size' => 1024,
                    'compression_applied' => false,
                    'compression_ratio' => 1.0,
                    'valid_for_transcription' => true,
                ],
            );

        $transcriptionService = $this->createMock(TranscriptionServiceInterface::class);
        $transcriptionService->expects($this->exactly(2))
            ->method('transcribe')
            ->willReturnCallback(function (string $audioPath): string {
                if ($audioPath === 'sermons/audio/section-1.mp3') {
                    throw new \RuntimeException('OpenAI unavailable');
                }

                return 'Recovered transcript';
            });

        $job = new TranscribeSpeechSegments($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class), $transcriptionService);

        $firstSection->refresh();
        $secondSection->refresh();

        $this->assertArrayNotHasKey('transcript', $firstSection->metadata ?? []);
        $this->assertSame('Recovered transcript', $secondSection->metadata['transcript'] ?? null);
        Storage::disk('public')->assertMissing('sermons/audio/section-1.mp3');
        Storage::disk('public')->assertMissing('sermons/audio/section-2.mp3');
    }

    #[Test]
    public function it_uses_time_boundaries_instead_of_the_stored_duration_when_deciding_to_transcribe(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'temp/source-video.mp4',
            'processing_id' => 'speech-time-bounds-proc',
        ]);

        Storage::disk('local')->put('temp/source-video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-bounds.mp3', 'audio');

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'start_time' => 30.0,
            'end_time' => 60.0,
            'duration' => 5.0,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->willReturn([
                'audio_path' => 'sermons/audio/section-bounds.mp3',
                'full_path' => Storage::disk('public')->path('sermons/audio/section-bounds.mp3'),
                'original_size' => 1024,
                'final_size' => 1024,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $transcriptionService = $this->createMock(TranscriptionServiceInterface::class);
        $transcriptionService->expects($this->once())
            ->method('transcribe')
            ->willReturn('Transcript from time bounds');

        $job = new TranscribeSpeechSegments($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class), $transcriptionService);

        $section->refresh();

        $this->assertSame('Transcript from time bounds', $section->metadata['transcript'] ?? null);
    }

    #[Test]
    public function it_transcribes_identified_non_sermon_speech_sections_created_by_classification(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'temp/source-video.mp4',
            'processing_id' => 'speech-classification-handoff',
            'extracted_date' => '2026-03-29',
            'extracted_service' => SermonService::MORNING->value,
        ]);

        Storage::disk('local')->put('temp/source-video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/classified-other.mp3', 'audio');

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 1,
            'start_time' => 0.0,
            'end_time' => 90.0,
            'duration' => 90.0,
        ]);

        LivestreamSegment::factory()->speech()->create([
            'media_processing_log_id' => $processingLog->id,
            'segment_order' => 2,
            'start_time' => 120.0,
            'end_time' => 1800.0,
            'duration' => 1680.0,
        ]);

        $classifyJob = new ClassifyServiceSections($processingLog);
        $classifyJob->handle(new ServiceSectionClassifier, new ServiceSectionSyncService);

        $sections = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->orderBy('section_order')
            ->get();

        $this->assertCount(2, $sections);
        $this->assertTrue($sections->every(
            fn (ServiceSection $section): bool => $section->status === ServiceSectionStatus::IDENTIFIED
        ));

        $otherSection = $sections->firstWhere('section_type', ServiceSectionType::OTHER);
        $this->assertInstanceOf(ServiceSection::class, $otherSection);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->once())
            ->method('extractOptimizedAudio')
            ->with(
                $this->callback(fn (string $path): bool => str_ends_with($path, 'source-video.mp4')),
                $this->callback(fn (object $segment): bool => (float) $segment->start_time === 0.0 && (float) $segment->end_time === 90.0),
                'speech-classification-handoff_section_'.$otherSection->id.'_speech.mp3'
            )
            ->willReturn([
                'audio_path' => 'sermons/audio/classified-other.mp3',
                'full_path' => Storage::disk('public')->path('sermons/audio/classified-other.mp3'),
                'original_size' => 1024,
                'final_size' => 1024,
                'compression_applied' => false,
                'compression_ratio' => 1.0,
                'valid_for_transcription' => true,
            ]);

        $transcriptionService = $this->createMock(TranscriptionServiceInterface::class);
        $transcriptionService->expects($this->once())
            ->method('transcribe')
            ->with(
                'sermons/audio/classified-other.mp3',
                'speech-classification-handoff-section-'.$otherSection->id,
                'public'
            )
            ->willReturn('Transcript from classified section');

        $job = new TranscribeSpeechSegments($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class), $transcriptionService);

        $otherSection->refresh();

        $this->assertSame('Transcript from classified section', $otherSection->metadata['transcript'] ?? null);
    }

    #[Test]
    public function it_no_ops_when_speech_segment_transcription_is_disabled(): void
    {
        config([
            'media-processing.section_classification.transcribe_speech_segments' => false,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'temp/source-video.mp4',
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::OTHER->value,
            'section_order' => 1,
            'duration' => 60.0,
            'metadata' => [
                'confidence_level' => 'low',
                'classification_mode' => 'audio_only',
            ],
        ]);

        $videoExtractor = $this->createMock(VideoExtractionService::class);
        $videoExtractor->expects($this->never())->method('extractOptimizedAudio');

        $transcriptionService = $this->createMock(TranscriptionServiceInterface::class);
        $transcriptionService->expects($this->never())->method('transcribe');

        $job = new TranscribeSpeechSegments($processingLog);
        $job->handle($videoExtractor, app(StorageAdapterHelper::class), $transcriptionService);
    }

    #[Test]
    public function it_targets_the_existing_audio_processing_queue(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->make();

        $job = new TranscribeSpeechSegments($processingLog);

        $this->assertSame('audio-processing', $job->queue);
    }
}
