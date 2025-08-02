<?php

namespace App\Services;

use App\Models\Sermon;
use Illuminate\Support\Facades\Storage;

class SermonVideoDisplayService
{
    public function getSermonWithVideo(int $sermonId): array
    {
        $sermon = Sermon::with('livestreamProcessing.segments')->find($sermonId);
        
        if (!$sermon) {
            throw new \Exception("Sermon with ID {$sermonId} not found");
        }

        return [
            'sermon' => $sermon,
            'has_video' => !empty($sermon->video_file_path),
            'video_url' => $sermon->video_file_path ? $this->getVideoUrl($sermon->video_file_path) : null,
            'source_type' => $sermon->source_type,
            'livestream_info' => $sermon->livestreamProcessing ? [
                'original_filename' => $sermon->livestreamProcessing->original_filename,
                'processing_date' => $sermon->livestreamProcessing->created_at,
                'segment_start' => $sermon->segment_start_time,
                'segment_end' => $sermon->segment_end_time,
                'total_segments' => $sermon->livestreamProcessing->segments->count(),
            ] : null,
        ];
    }
    
    public function getVideoPreviewData(int $sermonId): array
    {
        $sermon = Sermon::find($sermonId);
        
        if (!$sermon || !$sermon->video_file_path) {
            return ['has_video' => false];
        }
        
        $videoPath = $this->getVideoStoragePath($sermon->video_file_path);
        
        return [
            'has_video' => true,
            'video_url' => $this->getVideoUrl($sermon->video_file_path),
            'duration' => $this->getVideoDuration($videoPath),
            'file_size' => $this->getVideoFileSize($videoPath),
            'format' => pathinfo($sermon->video_file_path, PATHINFO_EXTENSION),
        ];
    }

    public function getVideoUrl(string $videoPath): string
    {
        $disk = Storage::disk(config('livestream-processing.sermon_disk', 'local'));
        
        if ($disk->exists($videoPath)) {
            return $disk->url($videoPath);
        }
        
        return '';
    }

    private function getVideoStoragePath(string $videoPath): string
    {
        $disk = Storage::disk(config('livestream-processing.sermon_disk', 'local'));
        return $disk->path($videoPath);
    }

    private function getVideoDuration(string $videoPath): ?float
    {
        if (!file_exists($videoPath)) {
            return null;
        }

        try {
            $ffprobe = config('livestream-processing.ffprobe_path', '/usr/bin/ffprobe');
            $command = [
                $ffprobe,
                '-v', 'quiet',
                '-show_entries', 'format=duration',
                '-of', 'csv=p=0',
                $videoPath
            ];
            
            $process = new \Symfony\Component\Process\Process($command);
            $process->run();
            
            if ($process->isSuccessful()) {
                return (float) trim($process->getOutput());
            }
            
            return null;
        } catch (\Exception $e) {
            \Log::warning('Failed to get video duration', [
                'video_path' => $videoPath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function getVideoFileSize(string $videoPath): ?int
    {
        if (!file_exists($videoPath)) {
            return null;
        }
        
        return filesize($videoPath);
    }

    public function getSermonsBySourceType(string $sourceType = null): array
    {
        $query = Sermon::with('livestreamProcessing');
        
        if ($sourceType) {
            $query->where('source_type', $sourceType);
        }
        
        return $query->orderBy('created_at', 'desc')->get()->map(function ($sermon) {
            return [
                'id' => $sermon->id,
                'title' => $sermon->title,
                'preacher' => $sermon->preacher,
                'date' => $sermon->date,
                'source_type' => $sermon->source_type,
                'has_video' => !empty($sermon->video_file_path),
                'livestream_processing_id' => $sermon->livestream_processing_id,
            ];
        })->toArray();
    }

    public function getLivestreamSourceIndicator(Sermon $sermon): array
    {
        if ($sermon->source_type !== 'livestream' || !$sermon->livestreamProcessing) {
            return ['is_livestream' => false];
        }

        return [
            'is_livestream' => true,
            'original_filename' => $sermon->livestreamProcessing->original_filename,
            'processing_status' => $sermon->livestreamProcessing->status,
            'segment_count' => $sermon->livestreamProcessing->segments->count(),
            'processing_date' => $sermon->livestreamProcessing->created_at->format('Y-m-d H:i:s'),
        ];
    }
}