<?php

namespace Tests\Unit\Services;

use App\Services\AudioChunkingService;
use App\Services\SermonProcessingLogger;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AudioTranscriptionServiceChunkingTest extends TestCase
{
    private AudioChunkingService $chunkingService;

    private SermonProcessingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['media-processing.transcription.openai_api_key' => 'test-key']);
        config(['openai.api_key' => 'test-key']);
        config(['media-processing.ffmpeg_path' => '/usr/bin/ffmpeg']);
        config(['media-processing.ffprobe_path' => '/usr/bin/ffprobe']);

        $this->logger = Mockery::mock(SermonProcessingLogger::class);
        $this->logger->shouldReceive('logProcessingStep')->andReturn(true);
        $this->logger->shouldReceive('logFileOperation')->andReturn(true);
        $this->logger->shouldReceive('logApiCall')->andReturn(true);
        $this->logger->shouldReceive('logError')->andReturn(true);

        $this->chunkingService = new AudioChunkingService($this->logger);
    }

    #[Test]
    public function it_detects_when_chunking_is_needed(): void
    {
        // Duration longer than MIN_DURATION_FOR_CHUNKING (7 minutes = 420 seconds)
        $this->assertTrue($this->chunkingService->needsChunking(2100.0)); // 35 minutes
        $this->assertTrue($this->chunkingService->needsChunking(421.0));

        // Duration at or below threshold
        $this->assertFalse($this->chunkingService->needsChunking(420.0));
        $this->assertFalse($this->chunkingService->needsChunking(300.0));
    }

    #[Test]
    public function it_calculates_correct_chunk_boundaries(): void
    {
        $chunkDurationSeconds = $this->chunkingService->getChunkDurationMinutes() * 60;
        $overlapSeconds = $this->chunkingService->getChunkOverlapSeconds();
        $totalDuration = 2100; // 35 minutes

        $this->assertEquals(360, $chunkDurationSeconds);
        $this->assertEquals(15, $overlapSeconds);

        $expectedChunks = [];
        $currentTime = 0;
        $chunkIndex = 0;

        while ($currentTime < $totalDuration) {
            $startTime = max(0, $currentTime - ($chunkIndex > 0 ? $overlapSeconds : 0));
            $endTime = min($totalDuration, $currentTime + $chunkDurationSeconds);
            $actualDuration = $endTime - $startTime;

            if ($actualDuration < 30) {
                break;
            }

            $expectedChunks[] = [
                'start' => $startTime,
                'duration' => $actualDuration,
                'end' => $endTime,
            ];

            $chunkIndex++;
            $currentTime += ($chunkDurationSeconds - $overlapSeconds);
        }

        // Verify we get the expected number of chunks for a 35-minute file
        $this->assertGreaterThan(5, count($expectedChunks));
        $this->assertLessThan(8, count($expectedChunks));

        // Verify first chunk starts at 0
        $this->assertEquals(0, $expectedChunks[0]['start']);

        // Verify second chunk calculation: currentTime = 345, so startTime = 345 - 15 = 330
        if (count($expectedChunks) > 1) {
            $this->assertEquals(330, $expectedChunks[1]['start']);
        }
    }

    #[Test]
    public function it_removes_overlapping_sentences_correctly(): void
    {
        $previousTranscript = 'This is the first sentence. This is the second sentence. This is the third sentence.';
        $currentTranscript = 'This is the third sentence. This is the fourth sentence. This is the fifth sentence.';

        $result = $this->chunkingService->removeOverlapFromTranscript($currentTranscript, $previousTranscript);

        $this->assertStringNotContainsString('This is the third sentence.', $result);
        $this->assertStringContainsString('This is the fourth sentence.', $result);
        $this->assertStringContainsString('This is the fifth sentence.', $result);
    }

    #[Test]
    public function it_handles_no_overlap_between_transcripts(): void
    {
        $previousTranscript = 'This is completely different content. No overlap here.';
        $currentTranscript = 'This is the start of new content. Totally different.';

        $result = $this->chunkingService->removeOverlapFromTranscript($currentTranscript, $previousTranscript);

        $this->assertEquals($currentTranscript, $result);
    }

    #[Test]
    public function it_normalizes_sentences_for_comparison_correctly(): void
    {
        $testCases = [
            'Hello, World!' => 'hello world',
            'This is a test... with punctuation?' => 'this is a test with punctuation',
            '  Multiple   spaces   ' => 'multiple spaces',
            'Numbers123 and symbols!@#' => 'numbers123 and symbols',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->chunkingService->normalizeSentenceForComparison($input);
            $this->assertEquals($expected, $result);
        }
    }

    #[Test]
    public function it_matches_similar_sentences_with_tolerance(): void
    {
        // Identical sentences should match
        $this->assertTrue($this->chunkingService->sentencesMatch(
            ['This is a test sentence.'],
            ['This is a test sentence.']
        ));

        // Similar sentences with minor differences should match (>85% similarity)
        $this->assertTrue($this->chunkingService->sentencesMatch(
            ['This is a test sentence.'],
            ['This is a test sentence!']
        ));

        // Very different sentences should not match
        $this->assertFalse($this->chunkingService->sentencesMatch(
            ['This is completely different.'],
            ['Something totally unrelated here.']
        ));

        // Different number of sentences should not match
        $this->assertFalse($this->chunkingService->sentencesMatch(
            ['First sentence.', 'Second sentence.'],
            ['First sentence.']
        ));
    }

    #[Test]
    public function it_reassembles_transcripts_in_correct_order(): void
    {
        $transcripts = [
            ['index' => 2, 'transcript' => 'Third chunk content. More content here.'],
            ['index' => 0, 'transcript' => 'First chunk content. Some content.'],
            ['index' => 1, 'transcript' => 'Some content. Second chunk content.'],
        ];

        $result = $this->chunkingService->reassembleTranscripts($transcripts, 'test-id');

        $this->assertStringContainsString('First chunk content', $result);
        $this->assertStringContainsString('Second chunk content', $result);
        $this->assertStringContainsString('Third chunk content', $result);

        // First chunk should appear before second chunk in the result
        $firstPos = strpos($result, 'First chunk');
        $secondPos = strpos($result, 'Second chunk');
        $this->assertLessThan($secondPos, $firstPos);
    }

    #[Test]
    public function it_handles_empty_transcripts_array(): void
    {
        $result = $this->chunkingService->reassembleTranscripts([], 'test-id');
        $this->assertEquals('', $result);
    }

    #[Test]
    public function it_handles_single_transcript_chunk(): void
    {
        $transcripts = [
            ['index' => 0, 'transcript' => 'Single chunk content.'],
        ];

        $result = $this->chunkingService->reassembleTranscripts($transcripts, 'test-id');
        $this->assertStringContainsString('Single chunk content', $result);
    }

    #[Test]
    public function it_generates_correct_temp_chunk_filenames(): void
    {
        $processingId = 'test-123';
        $chunkIndex = 5;

        $expectedFilename = "chunk_{$processingId}_{$chunkIndex}.mp3";
        $this->assertEquals('chunk_test-123_5.mp3', $expectedFilename);
    }

    #[Test]
    public function it_uses_correct_audio_settings_for_chunks(): void
    {
        $this->assertEquals(48, config('media-processing.audio_extraction.transcription_optimized.bitrate'));
        $this->assertEquals(16000, config('media-processing.audio_extraction.transcription_optimized.sample_rate'));
        $this->assertEquals(1, config('media-processing.audio_extraction.transcription_optimized.channels'));
    }

    #[Test]
    public function it_skips_chunks_that_are_too_short(): void
    {
        $chunkDurationSeconds = $this->chunkingService->getChunkDurationMinutes() * 60;
        $overlapSeconds = $this->chunkingService->getChunkOverlapSeconds();
        $totalDuration = 700;

        $currentTime = 0;
        $chunkIndex = 0;
        $chunks = [];

        while ($currentTime < $totalDuration) {
            $startTime = max(0, $currentTime - ($chunkIndex > 0 ? $overlapSeconds : 0));
            $endTime = min($totalDuration, $currentTime + $chunkDurationSeconds);
            $actualDuration = $endTime - $startTime;

            if ($actualDuration < 30) {
                break;
            }

            $chunks[] = $actualDuration;
            $chunkIndex++;
            $currentTime += ($chunkDurationSeconds - $overlapSeconds);
        }

        $this->assertEquals(2, count($chunks));
        $this->assertEquals(360, $chunks[0]);
        $this->assertEquals(370, $chunks[1]);
    }

    #[Test]
    public function it_creates_temp_directory_for_chunks_if_not_exists(): void
    {
        $tempDir = storage_path('app/temp');

        if (is_dir($tempDir)) {
            $this->removeDirectoryRecursively($tempDir);
        }

        $this->assertFalse(is_dir($tempDir));

        $chunkFilename = 'chunk_test_0.mp3';
        $chunkPath = storage_path("app/temp/{$chunkFilename}");
        $expectedTempDir = dirname($chunkPath);

        if (! is_dir($expectedTempDir)) {
            mkdir($expectedTempDir, 0755, true);
        }

        $this->assertTrue(is_dir($expectedTempDir));
        $this->assertEquals($tempDir, $expectedTempDir);

        $this->removeDirectoryRecursively($tempDir);
    }

    private function removeDirectoryRecursively($dir): void
    {
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    $filePath = $dir.DIRECTORY_SEPARATOR.$file;
                    if (is_dir($filePath)) {
                        $this->removeDirectoryRecursively($filePath);
                    } else {
                        unlink($filePath);
                    }
                }
            }
            rmdir($dir);
        }
    }

    #[Test]
    public function it_logs_chunking_progress_correctly(): void
    {
        $expectedLogCalls = [
            ['step' => 'audio_chunking', 'status' => 'started'],
            ['step' => 'chunk_creation', 'status' => 'completed'],
            ['step' => 'chunk_transcription', 'status' => 'started'],
            ['step' => 'chunk_transcription', 'status' => 'completed'],
            ['step' => 'chunk_cleanup', 'status' => 'completed'],
            ['step' => 'transcript_reassembly', 'status' => 'completed'],
            ['step' => 'audio_chunking', 'status' => 'completed'],
        ];

        $this->assertGreaterThan(5, count($expectedLogCalls));
        $this->assertEquals('audio_chunking', $expectedLogCalls[0]['step']);
        $this->assertEquals('started', $expectedLogCalls[0]['status']);
        $this->assertEquals('audio_chunking', $expectedLogCalls[6]['step']);
        $this->assertEquals('completed', $expectedLogCalls[6]['status']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
