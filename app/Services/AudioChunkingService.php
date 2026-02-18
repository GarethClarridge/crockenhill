<?php

namespace App\Services;

use App\Exceptions\TranscriptionException;
use Exception;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AudioChunkingService
{
    private const CHUNK_DURATION_MINUTES = 6;

    private const CHUNK_OVERLAP_SECONDS = 15;

    private const MIN_DURATION_FOR_CHUNKING = 420; // 7 minutes

    public function __construct(
        private readonly MediaProcessingLogger $logger
    ) {}

    /**
     * Determine if an audio file needs chunking based on duration
     */
    public function needsChunking(float $duration): bool
    {
        return $duration > self::MIN_DURATION_FOR_CHUNKING;
    }

    /**
     * Get audio duration in seconds using FFprobe
     *
     * @throws TranscriptionException When duration cannot be determined
     */
    public function getAudioDuration(string $filePath): float
    {
        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
                'timeout' => 60,
                'ffmpeg.threads' => 1,
            ]);

            $audio = $ffmpeg->open($filePath);
            $duration = $audio->getStreams()->first()->get('duration');

            return (float) $duration;
        } catch (Exception $e) {
            throw new TranscriptionException('Failed to get audio duration: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create audio chunks from the original file
     *
     * @param  string  $filePath  Full path to the original audio file
     * @param  string  $processingId  Processing ID for logging
     * @param  float  $duration  Total duration in seconds
     * @return array Array of chunk file paths
     *
     * @throws TranscriptionException When chunk creation fails
     */
    public function createAudioChunks(string $filePath, string $processingId, float $duration): array
    {
        $chunkDurationSeconds = self::CHUNK_DURATION_MINUTES * 60;
        $overlapSeconds = self::CHUNK_OVERLAP_SECONDS;
        $chunkRunId = (string) Str::uuid();

        $ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
            'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            'timeout' => 300,
            'ffmpeg.threads' => 1,
        ]);

        $audio = $ffmpeg->open($filePath);
        $chunks = [];
        $chunkIndex = 0;
        $currentTime = 0;

        while ($currentTime < $duration) {
            $startTime = max(0, $currentTime - ($chunkIndex > 0 ? $overlapSeconds : 0));
            $endTime = min($duration, $currentTime + $chunkDurationSeconds);
            $actualDuration = $endTime - $startTime;

            if ($actualDuration < 30) {
                break;
            }

            $chunkFilename = "chunk_{$processingId}_{$chunkRunId}_{$chunkIndex}.mp3";
            $chunkPath = storage_path("app/temp/{$chunkFilename}");

            $tempDir = dirname($chunkPath);
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            try {
                $format = new Mp3;
                $format->setAudioKiloBitrate(48)
                    ->setAudioChannels(1);

                $audio->filters()->clip(
                    TimeCode::fromSeconds($startTime),
                    TimeCode::fromSeconds($actualDuration)
                );
                $audio->save($format, $chunkPath);

                $chunks[] = $chunkPath;

                $this->logger->logProcessingStep(
                    $processingId,
                    'chunk_creation',
                    'completed',
                    [
                        'chunk_index' => $chunkIndex,
                        'start_time' => $startTime,
                        'duration' => $actualDuration,
                        'file_size' => filesize($chunkPath),
                        'chunk_path' => $chunkPath,
                    ]
                );
            } catch (Exception $e) {
                throw new TranscriptionException("Failed to create chunk {$chunkIndex}: ".$e->getMessage(), 0, $e);
            }

            $chunkIndex++;
            $currentTime += ($chunkDurationSeconds - $overlapSeconds);
        }

        return $chunks;
    }

    /**
     * Reassemble transcripts from chunks with overlap deduplication
     *
     * @param  array  $transcripts  Array of transcript data with indices and content
     * @param  string  $processingId  Processing ID for logging
     * @return string The reassembled transcript
     */
    public function reassembleTranscripts(array $transcripts, string $processingId): string
    {
        if (empty($transcripts)) {
            return '';
        }

        usort($transcripts, fn ($a, $b) => $a['index'] <=> $b['index']);

        $reassembled = '';
        $previousTranscript = '';

        foreach ($transcripts as $i => $transcriptData) {
            $transcript = trim($transcriptData['transcript']);

            if ($i === 0) {
                $reassembled = $transcript;
            } else {
                $deduplicated = $this->removeOverlapFromTranscript($transcript, $previousTranscript);
                $reassembled .= "\n\n".$deduplicated;
            }

            $previousTranscript = $transcript;
        }

        $this->logger->logProcessingStep(
            $processingId,
            'transcript_reassembly',
            'completed',
            [
                'chunk_count' => count($transcripts),
                'final_length' => strlen($reassembled),
            ]
        );

        return trim($reassembled);
    }

    /**
     * Remove overlapping content from the beginning of a transcript
     */
    public function removeOverlapFromTranscript(string $currentTranscript, string $previousTranscript): string
    {
        if (empty($previousTranscript) || empty($currentTranscript)) {
            return $currentTranscript;
        }

        $previousSentences = $this->splitIntoSentences($previousTranscript);
        $currentSentences = $this->splitIntoSentences($currentTranscript);

        if (empty($previousSentences) || empty($currentSentences)) {
            return $currentTranscript;
        }

        $maxOverlapSentences = min(3, count($previousSentences), count($currentSentences));
        $overlapFound = 0;

        for ($i = 1; $i <= $maxOverlapSentences; $i++) {
            $previousEnd = array_slice($previousSentences, -$i);
            $currentStart = array_slice($currentSentences, 0, $i);

            if ($this->sentencesMatch($previousEnd, $currentStart)) {
                $overlapFound = $i;
            }
        }

        if ($overlapFound > 0) {
            $remainingSentences = array_slice($currentSentences, $overlapFound);

            return implode(' ', $remainingSentences);
        }

        return $currentTranscript;
    }

    /**
     * Check if two arrays of sentences match with some tolerance
     */
    public function sentencesMatch(array $sentences1, array $sentences2): bool
    {
        if (count($sentences1) !== count($sentences2)) {
            return false;
        }

        foreach ($sentences1 as $i => $sentence1) {
            $sentence2 = $sentences2[$i];

            $norm1 = $this->normalizeSentenceForComparison($sentence1);
            $norm2 = $this->normalizeSentenceForComparison($sentence2);

            $similarity = 0;
            similar_text($norm1, $norm2, $similarity);

            if ($similarity < 85) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize sentence for comparison by removing punctuation and extra spaces
     */
    public function normalizeSentenceForComparison(string $sentence): string
    {
        $normalized = preg_replace('/[^\w\s]/', '', strtolower($sentence));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * Clean up temporary chunk files
     */
    public function cleanupChunkFiles(array $chunkPaths, string $processingId): void
    {
        foreach ($chunkPaths as $chunkPath) {
            if (file_exists($chunkPath)) {
                unlink($chunkPath);
            }
        }

        $this->logger->logProcessingStep(
            $processingId,
            'chunk_cleanup',
            'completed',
            ['cleaned_chunks' => count($chunkPaths)]
        );
    }

    /**
     * Compress audio file for transcription service
     *
     * @throws TranscriptionException When compression fails
     */
    public function compressAudioForTranscription(string $inputPath, string $processingId): string
    {
        $fallbackConfig = config('media-processing.audio_extraction.fallback_compression');
        $compressedPath = storage_path('app/temp/'.basename($inputPath, '.mp3').'_compressed_'.time().'.mp3');

        $tempDir = dirname($compressedPath);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);

            $audio = $ffmpeg->open($inputPath);

            $format = new Mp3;
            $format->setAudioKiloBitrate($fallbackConfig['bitrate'] ?? 32);
            $format->setAudioChannels($fallbackConfig['channels'] ?? 1);

            $audio->save($format, $compressedPath);

            if (! file_exists($compressedPath) || filesize($compressedPath) === 0) {
                throw new TranscriptionException('Compressed audio file was not created or is empty');
            }

            $this->logger->logFileOperation(
                $processingId,
                'audio_compression',
                $compressedPath,
                filesize($compressedPath)
            );

            Log::info('Fallback audio compression applied', [
                'processing_id' => $processingId,
                'input_path' => $inputPath,
                'output_path' => $compressedPath,
                'compression_settings' => [
                    'bitrate_kbps' => $fallbackConfig['bitrate'] ?? 32,
                    'channels' => $fallbackConfig['channels'] ?? 1,
                    'purpose' => 'transcription_size_reduction',
                ],
            ]);

            return $compressedPath;

        } catch (\Exception $e) {
            if (file_exists($compressedPath)) {
                unlink($compressedPath);
            }

            throw new TranscriptionException("Failed to compress audio for transcription: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get the chunk duration in minutes
     */
    public function getChunkDurationMinutes(): int
    {
        return self::CHUNK_DURATION_MINUTES;
    }

    /**
     * Get the chunk overlap in seconds
     */
    public function getChunkOverlapSeconds(): int
    {
        return self::CHUNK_OVERLAP_SECONDS;
    }

    /**
     * Split transcript into sentences (used for overlap detection)
     */
    private function splitIntoSentences(string $transcript): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $transcript);
        $sentences = array_map('trim', $sentences);
        $sentences = array_filter($sentences, function ($sentence) {
            return ! empty($sentence) && strlen($sentence) > 3;
        });

        return array_values($sentences);
    }
}
