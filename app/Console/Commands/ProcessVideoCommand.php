<?php

namespace App\Console\Commands;

use App\Data\LivestreamSegment;
use App\Models\LivestreamProcessingLog;
use App\Models\Sermon;
use App\Services\VideoExtractionService;
use App\Services\VideoStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessVideoCommand extends Command
{
    protected $signature = 'livestream:create-sermon {processing_id : The processing ID}';

    protected $description = 'Manually create a sermon from processed livestream segments';

    public function handle(VideoExtractionService $videoExtractor, VideoStorageService $videoStorage): int
    {
        $processingId = $this->argument('processing_id');

        $processingLog = LivestreamProcessingLog::where('processing_id', $processingId)->first();
        if (! $processingLog) {
            $this->error("Processing log not found for ID: {$processingId}");

            return 1;
        }

        // Get the longest speech segment as the sermon
        /** @var \App\Models\LivestreamSegment|null $sermonSegment */
        $sermonSegment = $processingLog->segments()
            ->where('classification', 'speech')
            ->orderBy('duration', 'desc')
            ->first();

        if (! $sermonSegment) {
            $this->error('No sermon segment found');

            return 1;
        }

        $this->info("Found sermon segment: {$sermonSegment->start_time}s to {$sermonSegment->end_time}s (duration: {$sermonSegment->duration}s)");

        // Create sermon record
        $slug = Str::slug('livestream-sermon-'.now()->format('Y-m-d').'-'.time());
        $sermon = Sermon::create([
            'title' => 'Livestream Sermon - '.now()->format('Y-m-d'),
            'date' => now()->format('Y-m-d'),
            'service' => 'morning',
            'preacher' => 'Unknown',
            'slug' => $slug,
            'source_type' => 'livestream',
            'livestream_processing_id' => $processingId,
            'segment_start_time' => $sermonSegment->start_time,
            'segment_end_time' => $sermonSegment->end_time,
            'filename' => 'sermons/'.$slug.'.mp3', // Temporary filename
            'video_file_path' => 'sermons/videos/'.$slug.'.mp4', // Temporary video path
        ]);

        $this->info("Created sermon record with ID: {$sermon->id}");

        // Try to extract video segment
        if ($processingLog->original_file_path && Storage::exists($processingLog->original_file_path)) {
            try {
                $inputPath = Storage::path($processingLog->original_file_path);

                $segmentData = new LivestreamSegment(
                    startTime: $sermonSegment->start_time,
                    endTime: $sermonSegment->end_time,
                    duration: $sermonSegment->duration,
                    classification: $sermonSegment->classification,
                    avgRms: $sermonSegment->avg_rms ?? -30.0,
                    peakRms: $sermonSegment->peak_rms ?? -20.0,
                    isSermonCandidate: true,
                    segmentOrder: $sermonSegment->segment_order ?? 1
                );

                // Extract video segment
                $tempVideoPath = $videoExtractor->extractSegmentAsFile($inputPath, $segmentData);

                // Move to permanent storage
                $videoFilename = $sermon->slug.'.mp4';
                $videoPath = 'sermons/videos/'.$videoFilename;

                Storage::disk(config('livestream-processing.sermon_disk', 'local'))
                    ->put($videoPath, file_get_contents($tempVideoPath));

                // Extract audio
                $audioPath = $videoExtractor->extractAudio($inputPath, $segmentData, [], $sermon->slug.'.mp3');

                // Update sermon with file paths
                $sermon->update([
                    'video_file_path' => $videoPath,
                    'filename' => $audioPath,
                ]);

                // Clean up temp file
                if (file_exists($tempVideoPath)) {
                    unlink($tempVideoPath);
                }

                $this->info('Extracted video and audio files successfully');
                $this->info("Video path: {$videoPath}");
                $this->info("Audio path: {$audioPath}");

            } catch (\Exception $e) {
                $this->error('Failed to extract video/audio: '.$e->getMessage());
            }
        }

        $this->info('Sermon creation completed!');
        $this->info('Sermon URL: /christ/sermons/'.$sermon->date->format('Y/m')."/{$sermon->slug}");

        return 0;
    }
}
