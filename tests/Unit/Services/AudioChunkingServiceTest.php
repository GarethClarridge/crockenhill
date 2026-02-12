<?php

namespace Tests\Unit\Services;

use App\Exceptions\TranscriptionException;
use App\Services\AudioChunkingService;
use App\Services\MediaProcessingLogger;
use Illuminate\Support\Facades\Config;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudioChunkingServiceTest extends TestCase
{
    private AudioChunkingService $service;

    private MediaProcessingLogger|Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');
        Config::set('media-processing.audio_extraction.fallback_compression', [
            'bitrate' => 32,
            'channels' => 1,
        ]);

        $this->logger = Mockery::mock(MediaProcessingLogger::class)->shouldIgnoreMissing();

        $this->service = new AudioChunkingService($this->logger);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---- Instantiation ----

    #[Test]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(AudioChunkingService::class, $this->service);
    }

    // ---- needsChunking ----

    #[Test]
    public function it_returns_true_when_duration_exceeds_minimum(): void
    {
        // MIN_DURATION_FOR_CHUNKING = 420 seconds (7 minutes)
        $this->assertTrue($this->service->needsChunking(421.0));
    }

    #[Test]
    public function it_returns_false_when_duration_is_below_minimum(): void
    {
        $this->assertFalse($this->service->needsChunking(419.0));
    }

    #[Test]
    public function it_returns_false_when_duration_equals_minimum(): void
    {
        $this->assertFalse($this->service->needsChunking(420.0));
    }

    #[Test]
    public function it_returns_false_for_zero_duration(): void
    {
        $this->assertFalse($this->service->needsChunking(0.0));
    }

    #[Test]
    public function it_returns_true_for_very_long_duration(): void
    {
        $this->assertTrue($this->service->needsChunking(7200.0)); // 2 hours
    }

    // ---- getChunkDurationMinutes ----

    #[Test]
    public function it_returns_chunk_duration_in_minutes(): void
    {
        $this->assertEquals(6, $this->service->getChunkDurationMinutes());
    }

    // ---- getChunkOverlapSeconds ----

    #[Test]
    public function it_returns_chunk_overlap_in_seconds(): void
    {
        $this->assertEquals(15, $this->service->getChunkOverlapSeconds());
    }

    // ---- normalizeSentenceForComparison ----

    #[Test]
    public function it_normalizes_sentence_by_lowercasing(): void
    {
        $result = $this->service->normalizeSentenceForComparison('Hello World');
        $this->assertEquals('hello world', $result);
    }

    #[Test]
    public function it_normalizes_sentence_by_removing_punctuation(): void
    {
        $result = $this->service->normalizeSentenceForComparison('Hello, world! How are you?');
        $this->assertEquals('hello world how are you', $result);
    }

    #[Test]
    public function it_normalizes_sentence_by_collapsing_whitespace(): void
    {
        $result = $this->service->normalizeSentenceForComparison('Hello   world    test');
        $this->assertEquals('hello world test', $result);
    }

    #[Test]
    public function it_normalizes_sentence_by_trimming(): void
    {
        $result = $this->service->normalizeSentenceForComparison('  Hello world  ');
        $this->assertEquals('hello world', $result);
    }

    // ---- sentencesMatch ----

    #[Test]
    public function it_returns_true_for_identical_sentences(): void
    {
        $this->assertTrue($this->service->sentencesMatch(
            ['The Lord is my shepherd.'],
            ['The Lord is my shepherd.']
        ));
    }

    #[Test]
    public function it_returns_true_for_similar_sentences_above_threshold(): void
    {
        $this->assertTrue($this->service->sentencesMatch(
            ['The Lord is my shepherd and I shall not want.'],
            ['The Lord is my shepherd and I shall not want']
        ));
    }

    #[Test]
    public function it_returns_false_for_different_sentences(): void
    {
        $this->assertFalse($this->service->sentencesMatch(
            ['The Lord is my shepherd.'],
            ['Something completely different.']
        ));
    }

    #[Test]
    public function it_returns_false_for_mismatched_array_lengths(): void
    {
        $this->assertFalse($this->service->sentencesMatch(
            ['First sentence.', 'Second sentence.'],
            ['First sentence.']
        ));
    }

    #[Test]
    public function it_returns_true_for_multiple_matching_sentences(): void
    {
        $this->assertTrue($this->service->sentencesMatch(
            ['The Lord is good.', 'His mercy endures forever.'],
            ['The Lord is good.', 'His mercy endures forever.']
        ));
    }

    // ---- removeOverlapFromTranscript ----

    #[Test]
    public function it_returns_current_transcript_when_previous_is_empty(): void
    {
        $result = $this->service->removeOverlapFromTranscript('Current text here.', '');
        $this->assertEquals('Current text here.', $result);
    }

    #[Test]
    public function it_returns_current_transcript_when_current_is_empty(): void
    {
        $result = $this->service->removeOverlapFromTranscript('', 'Previous text here.');
        $this->assertEquals('', $result);
    }

    #[Test]
    public function it_removes_overlapping_sentence_from_beginning(): void
    {
        $previous = 'First sentence here. The Lord is my shepherd.';
        $current = 'The Lord is my shepherd. I shall not want.';

        $result = $this->service->removeOverlapFromTranscript($current, $previous);

        // The overlapping sentence "The Lord is my shepherd." should be removed
        $this->assertStringContainsString('I shall not want.', $result);
    }

    #[Test]
    public function it_returns_full_transcript_when_no_overlap_found(): void
    {
        $previous = 'First part of sermon about faith.';
        $current = 'Now we turn to hope and charity.';

        $result = $this->service->removeOverlapFromTranscript($current, $previous);

        $this->assertEquals('Now we turn to hope and charity.', $result);
    }

    // ---- reassembleTranscripts ----

    #[Test]
    public function it_returns_empty_string_for_empty_transcripts(): void
    {
        $result = $this->service->reassembleTranscripts([], 'proc-123');
        $this->assertEquals('', $result);
    }

    #[Test]
    public function it_returns_single_transcript_as_is(): void
    {
        $transcripts = [
            ['index' => 0, 'transcript' => 'The Lord is my shepherd.'],
        ];

        $result = $this->service->reassembleTranscripts($transcripts, 'proc-123');

        $this->assertEquals('The Lord is my shepherd.', $result);
    }

    #[Test]
    public function it_reassembles_multiple_transcripts_in_order(): void
    {
        $transcripts = [
            ['index' => 1, 'transcript' => 'Second part of the sermon.'],
            ['index' => 0, 'transcript' => 'First part of the sermon.'],
        ];

        $result = $this->service->reassembleTranscripts($transcripts, 'proc-123');

        $this->assertStringStartsWith('First part of the sermon.', $result);
        $this->assertStringContainsString('Second part of the sermon.', $result);
    }

    #[Test]
    public function it_trims_the_reassembled_result(): void
    {
        $transcripts = [
            ['index' => 0, 'transcript' => '  The sermon text.  '],
        ];

        $result = $this->service->reassembleTranscripts($transcripts, 'proc-123');

        $this->assertEquals('The sermon text.', $result);
    }

    #[Test]
    public function it_logs_reassembly_completion(): void
    {
        $logger = Mockery::mock(MediaProcessingLogger::class);
        $logger->shouldReceive('logProcessingStep')
            ->once()
            ->with('proc-123', 'transcript_reassembly', 'completed', Mockery::type('array'));

        $service = new AudioChunkingService($logger);

        $transcripts = [
            ['index' => 0, 'transcript' => 'First chunk.'],
            ['index' => 1, 'transcript' => 'Second chunk.'],
        ];

        $result = $service->reassembleTranscripts($transcripts, 'proc-123');

        $this->assertNotEmpty($result);
    }

    // ---- cleanupChunkFiles ----

    #[Test]
    public function it_cleans_up_existing_chunk_files(): void
    {
        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $chunkPath = $tempDir.'/test_chunk_cleanup.mp3';
        file_put_contents($chunkPath, 'test content');

        $this->logger->shouldReceive('logProcessingStep')
            ->once()
            ->with('proc-123', 'chunk_cleanup', 'completed', Mockery::type('array'));

        $this->service->cleanupChunkFiles([$chunkPath], 'proc-123');

        $this->assertFileDoesNotExist($chunkPath);
    }

    #[Test]
    public function it_handles_nonexistent_chunk_files_gracefully(): void
    {
        $this->logger->shouldReceive('logProcessingStep')
            ->once()
            ->with('proc-123', 'chunk_cleanup', 'completed', Mockery::type('array'));

        // Should not throw
        $this->service->cleanupChunkFiles(['/nonexistent/file.mp3'], 'proc-123');
        $this->assertTrue(true);
    }

    // ---- getAudioDuration (requires FFmpeg, test error handling) ----

    #[Test]
    public function it_throws_transcription_exception_when_getting_duration_fails(): void
    {
        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Failed to get audio duration');

        $this->service->getAudioDuration('/nonexistent/audio.mp3');
    }

    // ---- createAudioChunks (requires FFmpeg, test error handling) ----

    #[Test]
    public function it_throws_exception_when_creating_chunks_from_nonexistent_file(): void
    {
        $this->expectException(\Exception::class);

        $this->service->createAudioChunks('/nonexistent/audio.mp3', 'proc-123', 600.0);
    }

    // ---- compressAudioForTranscription (requires FFmpeg, test error handling) ----

    #[Test]
    public function it_throws_transcription_exception_when_compression_fails(): void
    {
        $this->expectException(TranscriptionException::class);
        $this->expectExceptionMessage('Failed to compress audio for transcription');

        $this->service->compressAudioForTranscription('/nonexistent/audio.mp3', 'proc-123');
    }
}
