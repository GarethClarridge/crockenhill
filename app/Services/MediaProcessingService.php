<?php

namespace App\Services;

use App\Enums\ProcessingStatus;
use App\Models\SermonProcessingLog;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MediaProcessingService
{
    public function __construct(
        private VideoSegmentationService $segmentationService,
        private SermonProcessingService $sermonProcessor,
        private VideoExtractionService $videoExtractor,
    ) {}

    /**
     * Entry Point 1: Full livestream processing
     */
    public function processLivestream(UploadedFile $file, ?SermonProcessingLog $existingLog = null): ProcessingResult
    {
        $processingId = $existingLog ? $existingLog->processing_id : Str::uuid()->toString();
        $extractedSegment = null;
        $fullVideoPath = null;

        try {
            Log::info('Starting livestream processing', [
                'processing_id' => $processingId,
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);

            // Use existing log or create new one
            if ($existingLog) {
                $log = $existingLog;
            } else {
                $log = SermonProcessingLog::create([
                    'processing_id' => $processingId,
                    'source_type' => 'livestream',
                    'status' => ProcessingStatus::PENDING,
                    'original_filename' => $file->getClientOriginalName(),
                    'current_step' => 'initiated',
                    'source_metadata' => [
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ],
                ]);
            }

            // Update to processing status
            $log->markAsProcessing('generating_rms_log');

            // Step 1: Store the video file temporarily
            $tempVideoPath = $file->store('temp', 'local');
            $fullVideoPath = storage_path('app/'.$tempVideoPath);

            // Step 2: Generate RMS log
            $log->updateStep('generating_rms_log');
            $log->refresh(); // Force database commit
            $rmsLogPath = $this->segmentationService->generateRmsLog($fullVideoPath);

            // Step 3: Analyze segments using RMS log
            $log->updateStep('analyzing_segments');
            $log->refresh(); // Force database commit
            $analysisResult = $this->segmentationService->analyzeSegments($rmsLogPath);
            $segments = $analysisResult['segments']; // Extract segments from analysis result
            $sermonSegment = collect($segments)->first(function ($segment) {
                return $segment->isSermonCandidate ?? false;
            });

            $log->updateStep('segment_analysis_complete');
            $log->refresh(); // Force database commit

            if (! $sermonSegment) {
                $log->markAsFailed('No sermon segment found in livestream');

                return ProcessingResult::failure(
                    $processingId,
                    'No sermon segment found in livestream',
                    'NO_SERMON_SEGMENT_FOUND'
                );
            }

            $log->updateStep('extracting_sermon_segment');
            $log->refresh(); // Force database commit

            // Step 4: Extract the sermon segment from the full video
            $extractedSegment = $this->videoExtractor->extractSegmentAsUpload($fullVideoPath, $sermonSegment);

            // Update metadata with segment information
            $log->source_metadata = array_merge($log->source_metadata ?? [], [
                'sermon_segment' => [
                    'start_time' => $sermonSegment->startTime ?? 0,
                    'end_time' => $sermonSegment->endTime ?? 0,
                    'duration' => $sermonSegment->duration ?? 0,
                ],
            ]);
            $log->save();

            // Step 5: Continue with video processing pipeline using extracted segment
            $log->updateStep('processing_extracted_segment');
            $log->refresh(); // Force database commit
            $result = $this->processVideo($extractedSegment, $log);

            // Clean up temporary files
            if ($fullVideoPath && file_exists($fullVideoPath)) {
                unlink($fullVideoPath);
            }
            // Clean up extracted segment file
            /** @phpstan-ignore-next-line */
            if ($extractedSegment && file_exists($extractedSegment->getRealPath())) {
                unlink($extractedSegment->getRealPath());
            }

            return $result;

        } catch (\Exception $e) {
            // Clean up temporary files on error
            if ($fullVideoPath && file_exists($fullVideoPath)) {
                unlink($fullVideoPath);
            }
            /** @phpstan-ignore-next-line */
            if ($extractedSegment && file_exists($extractedSegment->getRealPath())) {
                unlink($extractedSegment->getRealPath());
            }
            Log::error('Failed to process livestream', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ProcessingResult::failure(
                $processingId,
                'Failed to process livestream: '.$e->getMessage(),
                'LIVESTREAM_PROCESSING_FAILED'
            );
        }
    }

    /**
     * Entry Point 2: Direct sermon video processing
     */
    public function processVideo(UploadedFile $file, ?SermonProcessingLog $existingLog = null): ProcessingResult
    {
        $processingId = $existingLog ? $existingLog->processing_id : Str::uuid()->toString();

        try {
            Log::info('Starting video processing', [
                'processing_id' => $processingId,
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);

            if (! $existingLog) {
                $log = SermonProcessingLog::create([
                    'processing_id' => $processingId,
                    'source_type' => 'video',
                    'status' => ProcessingStatus::PENDING,
                    'original_filename' => $file->getClientOriginalName(),
                    'current_step' => 'initiated',
                    'source_metadata' => [
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ],
                ]);
            } else {
                $log = $existingLog;
            }

            $log->markAsProcessing('extracting_audio');

            // Step 1: Extract audio from video using FFmpeg
            $log->updateStep('extracting_audio');
            $log->refresh(); // Force database commit
            $audioFile = $this->extractAudioFromVideo($file);

            // Step 2: Continue with audio processing pipeline
            $log->updateStep('processing_audio');
            $log->refresh(); // Force database commit

            return $this->processAudio($audioFile, $log);

        } catch (\Exception $e) {
            Log::error('Failed to process video', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ProcessingResult::failure(
                $processingId,
                'Failed to process video: '.$e->getMessage(),
                'VIDEO_PROCESSING_FAILED'
            );
        }
    }

    /**
     * Entry Point 3: Direct audio processing (existing flow)
     */
    public function processAudio(UploadedFile $file, ?SermonProcessingLog $existingLog = null): ProcessingResult
    {
        $processingId = $existingLog ? $existingLog->processing_id : Str::uuid()->toString();

        try {
            Log::info('Starting audio processing', [
                'processing_id' => $processingId,
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
            ]);

            if (! $existingLog) {
                $log = SermonProcessingLog::create([
                    'processing_id' => $processingId,
                    'source_type' => 'audio',
                    'status' => ProcessingStatus::PENDING,
                    'original_filename' => $file->getClientOriginalName(),
                    'current_step' => 'initiated',
                    'source_metadata' => [
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ],
                ]);
            } else {
                $log = $existingLog;
            }

            // Begin audio processing
            $log->updateStep('transcribing_audio');
            $log->refresh(); // Force database commit

            // Use existing sermon processor (reuse working code!)
            $result = $this->sermonProcessor->processSermon($file);

            // Update the processing log with the correct source type
            $finalLog = SermonProcessingLog::where('processing_id', $result->processingId)->first();
            if ($finalLog && ! $existingLog) {
                $finalLog->update(['source_type' => 'audio']);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Failed to process audio', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ProcessingResult::failure(
                $processingId,
                'Failed to process audio: '.$e->getMessage(),
                'AUDIO_PROCESSING_FAILED'
            );
        }
    }

    /**
     * Extract audio from video file
     */
    private function extractAudioFromVideo(UploadedFile $videoFile): UploadedFile
    {
        $tempPath = storage_path('app/temp/'.Str::uuid().'.mp3');
        $this->ensureDirectoryExists(dirname($tempPath));

        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('livestream-processing.ffmpeg_path'),
                'ffprobe.binaries' => config('livestream-processing.ffprobe_path'),
            ]);

            $video = $ffmpeg->open($videoFile->getRealPath());

            $format = new Mp3;
            // Use optimized settings for transcription to keep file size under 25MB
            $transcriptionConfig = config('livestream-processing.audio_extraction.transcription_optimized');
            $format->setAudioKiloBitrate($transcriptionConfig['bitrate'] ?? 48);
            $format->setAudioChannels($transcriptionConfig['channels'] ?? 1);
            // Note: Sample rate is handled by FFmpeg defaults (typically 16kHz for speech)

            $video->save($format, $tempPath);

            Log::info('Audio extracted from video', [
                'video_path' => $videoFile->getRealPath(),
                'audio_path' => $tempPath,
                'file_size' => filesize($tempPath),
                'compression_settings' => [
                    'bitrate_kbps' => $transcriptionConfig['bitrate'] ?? 48,
                    'channels' => $transcriptionConfig['channels'] ?? 1,
                    'optimized_for' => 'transcription',
                ],
            ]);

            // Create UploadedFile wrapper for extracted audio
            return new UploadedFile(
                $tempPath,
                pathinfo($videoFile->getClientOriginalName(), PATHINFO_FILENAME).'.mp3',
                'audio/mpeg',
                null,
                true // Mark as test file to skip is_uploaded_file check
            );

        } catch (\Exception $e) {
            Log::error('Failed to extract audio from video', [
                'video_path' => $videoFile->getRealPath(),
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Failed to extract audio from video: '.$e->getMessage());
        }
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
