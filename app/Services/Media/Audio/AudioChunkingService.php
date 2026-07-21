<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Exceptions\TranscriptionException;
use App\Services\Processing\SermonProcessingLogger;
use Exception;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Media\Audio;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AudioChunkingService
{
    private const CHUNK_DURATION_MINUTES = 6;

    private const CHUNK_OVERLAP_SECONDS = 15;

    private const MIN_DURATION_FOR_CHUNKING = 420; // 7 minutes

    public function __construct(
        private readonly SermonProcessingLogger $logger
    ) {}

    /**
     * Determine if an audio file needs chunking based on duration.
     *
     * Chunking is required for files exceeding the Whisper API's 25MB limit.
     * While this check is duration-based, it targets files that would likely
     * exceed the size limit even after standard compression.
     *
     * @param  float  $duration  Audio duration in seconds
     * @return bool True if the file should be chunked
     */
    public function needsChunking(float $duration): bool
    {
        return $duration > self::MIN_DURATION_FOR_CHUNKING;
    }

    /**
     * Get audio duration in seconds using FFprobe.
     *
     * @param  string  $filePath  Full path to the audio file
     * @return float Duration in seconds
     *
     * @throws TranscriptionException When duration cannot be determined or FFprobe fails
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
            $stream = $audio->getStreams()->first();
            if ($stream === null) {
                throw new TranscriptionException('Failed to get audio duration: no audio stream found.');
            }

            $duration = $stream->get('duration');

            return (float) $duration;
        } catch (Exception $e) {
            throw new TranscriptionException('Failed to get audio duration: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Create audio chunks from the original file.
     *
     * Splits a long audio file into smaller segments of approximately 6 minutes,
     * with a 15-second overlap to ensure no content is lost at the boundaries.
     * Chunks are saved as low-bitrate mono MP3s to minimize transcription costs.
     *
     * @param  string  $filePath  Full path to the original audio file
     * @param  string  $processingId  Processing ID for logging
     * @param  float  $duration  Total duration in seconds
     * @return list<string> Array of absolute paths to the created chunk files
     *
     * @throws TranscriptionException When FFmpeg fails to create a chunk or directory
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
                $audio = new Audio($filePath, $ffmpeg->getFFMpegDriver(), $ffmpeg->getFFProbe());

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
     * Reassemble individual chunk transcripts into a single coherent text.
     *
     * Orders chunks by their original index and removes overlapping content
     * at the boundaries using fuzzy sentence matching to handle transcription
     * variations between chunks.
     *
     * @param  list<array{index: int, transcript: string, start_time: float}>  $transcripts  Ordered transcript segments
     * @param  string  $processingId  Processing ID for logging
     * @return string The complete reassembled and deduplicated transcript
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
     * Remove overlapping content from the start of a transcript segment.
     *
     * Compares the end of the previous segment with the start of the current
     * segment. If matching sentences are found (up to 3), they are stripped
     * from the current segment to prevent repetition in the final output.
     *
     * @param  string  $currentTranscript  The new transcript segment to be appended
     * @param  string  $previousTranscript  The transcript segment immediately preceding it
     * @return string The current transcript with leading overlap removed
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
     * Check if two sequences of sentences match with a defined similarity tolerance.
     *
     * Uses a similarity threshold of 85% to account for slight variations in
     * Whisper's output across chunk boundaries (e.g., minor punctuation or
     * word confidence differences).
     *
     * @param  list<string>  $sentences1  First sequence of sentences (typically the end of a chunk)
     * @param  list<string>  $sentences2  Second sequence of sentences (typically the start of the next chunk)
     * @return bool True if the sequences are considered equivalent
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
     * Normalize a sentence to its canonical form for fuzzy comparison.
     *
     * Converts to lowercase, strips all non-alphanumeric characters, and
     * collapses multiple spaces into one.
     *
     * @param  string  $sentence  The raw sentence text
     * @return string The normalized alphanumeric string
     */
    public function normalizeSentenceForComparison(string $sentence): string
    {
        $normalized = preg_replace('/[^\w\s]/', '', strtolower($sentence)) ?? strtolower($sentence);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * Clean up temporary chunk files from the filesystem.
     *
     * @param  list<string>  $chunkPaths  Array of absolute paths to delete
     * @param  string  $processingId  Processing ID for logging
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
     * Compress an audio file to satisfy Whisper API's size constraints.
     *
     * Produces a mono MP3 at the configured bitrate (default 32kbps) which
     * significantly reduces file size while maintaining enough quality for
     * speech-to-text accuracy.
     *
     * @param  string  $inputPath  Absolute path to the source audio file
     * @param  string  $processingId  Processing ID for logging
     * @return string Absolute path to the compressed file
     *
     * @throws TranscriptionException When FFmpeg fails or produced file is empty
     */
    public function compressAudioForTranscription(string $inputPath, string $processingId): string
    {
        $fallbackConfig = TranscriptionAudioProfile::fallback();
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
            $format->setAudioKiloBitrate($fallbackConfig['bitrate']);
            $format->setAudioChannels($fallbackConfig['channels']);

            $audio->save($format, $compressedPath);

            if (! file_exists($compressedPath) || filesize($compressedPath) === 0) {
                throw new TranscriptionException('Compressed audio file was not created or is empty');
            }

            $compressedSize = filesize($compressedPath);
            $this->logger->logFileOperation(
                $processingId,
                'audio_compression',
                $compressedPath,
                $compressedSize === false ? null : $compressedSize
            );

            Log::info('Fallback audio compression applied', [
                'processing_id' => $processingId,
                'input_path' => $inputPath,
                'output_path' => $compressedPath,
                'compression_settings' => [
                    'bitrate_kbps' => $fallbackConfig['bitrate'],
                    'channels' => $fallbackConfig['channels'],
                    'purpose' => 'transcription_size_reduction',
                ],
            ]);

            return $compressedPath;

        } catch (Exception $e) {
            if (file_exists($compressedPath)) {
                unlink($compressedPath);
            }

            throw new TranscriptionException("Failed to compress audio for transcription: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Get the configured chunk duration in minutes.
     *
     * Used by transcription services to calculate expected segment offsets.
     */
    public function getChunkDurationMinutes(): int
    {
        return self::CHUNK_DURATION_MINUTES;
    }

    /**
     * Get the configured chunk overlap in seconds.
     *
     * Used to ensure overlapping regions are correctly handled during reassembly.
     */
    public function getChunkOverlapSeconds(): int
    {
        return self::CHUNK_OVERLAP_SECONDS;
    }

    /**
     * Split transcript into sentences (used for overlap detection)
     *
     * @return array<int, string>
     */
    private function splitIntoSentences(string $transcript): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $transcript);
        if ($sentences === false) {
            return [];
        }

        $sentences = array_map('trim', $sentences);
        $sentences = array_filter($sentences, function ($sentence) {
            return ! empty($sentence) && strlen($sentence) > 3;
        });

        return array_values($sentences);
    }
}
