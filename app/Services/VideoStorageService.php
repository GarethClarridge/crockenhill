<?php

namespace App\Services;

use App\Data\LivestreamSegment;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\Coordinate\TimeCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;

class VideoStorageService
{
    private FFMpeg $ffmpeg;
    private string $tempDisk;
    private string $permanentDisk;
    private string $videoPath;
    private string $audioPath;

    public function __construct()
    {
        $this->ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => config('livestream-processing.ffmpeg_path'),
            'ffprobe.binaries' => config('livestream-processing.ffprobe_path'),
            'timeout' => config('livestream-processing.processing_timeout'),
        ]);

        $this->tempDisk = config('livestream-processing.temp_disk', 'local');
        $this->permanentDisk = config('livestream-processing.sermon_disk', 'local');
        $this->videoPath = config('livestream-processing.storage.video_path', 'sermons/videos');
        $this->audioPath = config('livestream-processing.storage.audio_path', 'sermons/audio');
    }

    public function storeUploadedVideo(UploadedFile $file): array
    {
        try {
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $tempPath = 'livestream/temp/' . $filename;
            
            $storedPath = $file->storeAs('livestream/temp', $filename, $this->tempDisk);
            $fullPath = Storage::disk($this->tempDisk)->path($storedPath);

            Log::info('Video uploaded and stored temporarily', [
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'file_size' => $file->getSize()
            ]);

            return [
                'temp_path' => $storedPath,
                'full_path' => $fullPath,
                'original_filename' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to store uploaded video', [
                'error' => $e->getMessage(),
                'original_name' => $file->getClientOriginalName()
            ]);

            throw $e;
        }
    }

    public function extractVideoSegmentWithOriginalQuality(string $inputPath, float $startTime, float $endTime): string
    {
        try {
            // Use php-ffmpeg package for cleaner FFmpeg integration
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries' => config('livestream-processing.ffmpeg_path'),
                'ffprobe.binaries' => config('livestream-processing.ffprobe_path'),
            ]);
            
            $video = $ffmpeg->open(Storage::path($inputPath));
            
            // Extract segment preserving original quality (stream copy)
            $tempPath = storage_path('app/temp/' . Str::uuid() . '.mp4');
            
            $format = new \FFMpeg\Format\Video\DefaultVideo();
            // Add the critical '-c copy' parameter for true stream copy
            $format->setAdditionalParameters(['-c', 'copy']);
            
            $video
                ->clip(TimeCode::fromSeconds($startTime), TimeCode::fromSeconds($endTime - $startTime))
                ->save($format, $tempPath);
            
            Log::info('Video segment extracted with original quality', [
                'input_path' => $inputPath,
                'output_path' => $tempPath,
                'start_time' => $startTime,
                'end_time' => $endTime
            ]);
            
            return $tempPath;

        } catch (\Exception $e) {
            Log::error('Failed to extract video segment', [
                'error' => $e->getMessage(),
                'input_path' => $inputPath,
                'start_time' => $startTime,
                'end_time' => $endTime
            ]);

            throw $e;
        }
    }
    
    public function extractVideoSegment(
        string $inputVideoPath,
        LivestreamSegment $segment,
        string $outputFilename = null
    ): string {
        return $this->extractVideoSegmentWithOriginalQuality(
            $inputVideoPath,
            $segment->startTime,
            $segment->endTime
        );
    }

    public function extractAudioFromSegment(
        string $inputVideoPath,
        LivestreamSegment $segment,
        string $outputFilename = null
    ): string {
        try {
            $outputFilename = $outputFilename ?: Str::uuid() . '_sermon.mp3';
            $outputPath = $this->audioPath . '/' . $outputFilename;
            $fullOutputPath = Storage::disk($this->permanentDisk)->path($outputPath);

            $this->ensureDirectoryExists(dirname($fullOutputPath));

            $video = $this->ffmpeg->open($inputVideoPath);
            
            $format = new Mp3();
            $format->setAudioKiloBitrate(128);

            $startTime = TimeCode::fromSeconds($segment->startTime);
            $duration = TimeCode::fromSeconds($segment->duration);

            $video->clip($startTime, $duration)
                  ->save($format, $fullOutputPath);

            Log::info('Audio extracted from segment', [
                'input_path' => $inputVideoPath,
                'output_path' => $outputPath,
                'start_time' => $segment->startTime,
                'duration' => $segment->duration
            ]);

            return $outputPath;

        } catch (\Exception $e) {
            Log::error('Failed to extract audio from segment', [
                'error' => $e->getMessage(),
                'input_path' => $inputVideoPath,
                'segment_start' => $segment->startTime,
                'segment_duration' => $segment->duration
            ]);

            throw $e;
        }
    }

    public function moveToSermonStorage(string $tempVideoPath, string $sermonSlug): array
    {
        try {
            $videoFilename = $sermonSlug . '.mp4';
            $audioFilename = $sermonSlug . '.mp3';
            
            $videoPath = $this->videoPath . '/' . $videoFilename;
            $audioPath = $this->audioPath . '/' . $audioFilename;

            $fullVideoPath = Storage::disk($this->permanentDisk)->path($videoPath);
            $fullAudioPath = Storage::disk($this->permanentDisk)->path($audioPath);

            $this->ensureDirectoryExists(dirname($fullVideoPath));
            $this->ensureDirectoryExists(dirname($fullAudioPath));

            Storage::disk($this->tempDisk)->move($tempVideoPath, $videoPath);

            $video = $this->ffmpeg->open(Storage::disk($this->permanentDisk)->path($videoPath));
            $format = new Mp3();
            $format->setAudioKiloBitrate(128);
            
            $video->save($format, $fullAudioPath);

            Log::info('Files moved to sermon storage', [
                'video_path' => $videoPath,
                'audio_path' => $audioPath,
                'sermon_slug' => $sermonSlug
            ]);

            return [
                'video_path' => $videoPath,
                'audio_path' => $audioPath,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to move files to sermon storage', [
                'error' => $e->getMessage(),
                'temp_path' => $tempVideoPath,
                'sermon_slug' => $sermonSlug
            ]);

            throw $e;
        }
    }

    public function cleanupTemporaryFiles(string $processingId): void
    {
        Storage::deleteDirectory("temp/livestreams/{$processingId}");
        
        Log::info('Temporary files cleaned up', ['processing_id' => $processingId]);
    }

    public function cleanupExpiredFiles(): int
    {
        $deletedCount = 0;
        $retentionHours = config('livestream-processing.temp_file_retention_hours');
        $cutoffTime = now()->subHours($retentionHours);

        try {
            $tempDirectory = 'livestream/temp';
            $files = Storage::disk($this->tempDisk)->files($tempDirectory);

            foreach ($files as $file) {
                $fileTime = Storage::disk($this->tempDisk)->lastModified($file);
                
                if ($fileTime < $cutoffTime->timestamp) {
                    Storage::disk($this->tempDisk)->delete($file);
                    $deletedCount++;
                }
            }

            Log::info('Expired temporary files cleaned up', [
                'deleted_count' => $deletedCount,
                'retention_hours' => $retentionHours
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to cleanup expired files', [
                'error' => $e->getMessage()
            ]);
        }

        return $deletedCount;
    }

    public function getStorageStats(): array
    {
        try {
            $tempDisk = Storage::disk($this->tempDisk);
            $permanentDisk = Storage::disk($this->permanentDisk);

            $tempFiles = $tempDisk->files('livestream/temp');
            $videoFiles = $permanentDisk->files($this->videoPath);
            $audioFiles = $permanentDisk->files($this->audioPath);

            $tempSize = array_sum(array_map(fn($file) => $tempDisk->size($file), $tempFiles));
            $videoSize = array_sum(array_map(fn($file) => $permanentDisk->size($file), $videoFiles));
            $audioSize = array_sum(array_map(fn($file) => $permanentDisk->size($file), $audioFiles));

            return [
                'temp_files_count' => count($tempFiles),
                'temp_files_size' => $tempSize,
                'video_files_count' => count($videoFiles),
                'video_files_size' => $videoSize,
                'audio_files_count' => count($audioFiles),
                'audio_files_size' => $audioSize,
                'total_size' => $tempSize + $videoSize + $audioSize,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get storage stats', [
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    public function validateStorageSpace(int $requiredBytes): bool
    {
        try {
            $available = disk_free_space(Storage::disk($this->tempDisk)->path(''));
            return $available > ($requiredBytes * 2);
        } catch (\Exception $e) {
            Log::warning('Could not validate storage space', [
                'error' => $e->getMessage()
            ]);
            
            return true;
        }
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    public function getVideoUrl(string $videoPath): string
    {
        return Storage::disk($this->permanentDisk)->url($videoPath);
    }

    public function getAudioUrl(string $audioPath): string
    {
        return Storage::disk($this->permanentDisk)->url($audioPath);
    }

    public function videoExists(string $videoPath): bool
    {
        return Storage::disk($this->permanentDisk)->exists($videoPath);
    }

    public function audioExists(string $audioPath): bool
    {
        return Storage::disk($this->permanentDisk)->exists($audioPath);
    }
}