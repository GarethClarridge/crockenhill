<?php

namespace App\Services;

use App\Data\LivestreamSegment;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoSegmentationService
{
    private FFMpeg $ffmpeg;
    private FFProbe $ffprobe;
    private float $rmsThreshold;
    private float $minSectionDuration;
    private float $minSermonDuration;
    private string $tempDisk;

    public function __construct()
    {
        $this->ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => config('livestream-processing.ffmpeg_path'),
            'ffprobe.binaries' => config('livestream-processing.ffprobe_path'),
            'timeout' => config('livestream-processing.processing_timeout'),
        ]);

        $this->ffprobe = FFProbe::create([
            'ffprobe.binaries' => config('livestream-processing.ffprobe_path'),
        ]);

        $this->rmsThreshold = config('livestream-processing.rms_threshold', 0.1);
        $this->minSectionDuration = config('livestream-processing.min_section_duration', 30.0);
        $this->minSermonDuration = config('livestream-processing.min_sermon_duration', 300.0);
        $this->tempDisk = config('livestream-processing.temp_disk', 'local');
    }

    public function generateRmsLog(string $videoPath): string
    {
        $rmsLogPath = 'temp/rms_' . Str::uuid() . '.log';
        $fullRmsLogPath = Storage::disk($this->tempDisk)->path($rmsLogPath);

        try {
            // Include pts_time for accurate timestamps as specified in design
            $command = [
                config('livestream-processing.ffmpeg_path'),
                '-i', $videoPath,
                '-af', "astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level:key=frame.pts_time:file={$fullRmsLogPath}",
                '-f', 'null',
                '-'
            ];

            $process = new \Symfony\Component\Process\Process($command);
            $process->setTimeout(7200); // 2 hour timeout for large files
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Symfony\Component\Process\Exception\ProcessFailedException($process);
            }
            
            if (!file_exists($fullRmsLogPath) || filesize($fullRmsLogPath) === 0) {
                throw new \Exception('Failed to generate RMS log file');
            }

            Log::info('RMS log generated successfully', ['path' => $rmsLogPath]);

            return $rmsLogPath;

        } catch (\Exception $e) {
            Log::error('Failed to generate RMS log', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath,
                'rms_log_path' => $rmsLogPath
            ]);

            throw $e;
        }
    }

    /**
     * @return LivestreamSegment[]
     */
    public function analyzeSegments(string $rmsLogPath): array
    {
        try {
            $fullRmsLogPath = Storage::disk($this->tempDisk)->path($rmsLogPath);
            
            if (!file_exists($fullRmsLogPath)) {
                throw new \Exception('RMS log file not found: ' . $fullRmsLogPath);
            }

            $logContent = file_get_contents($fullRmsLogPath);
            $segments = $this->parseRmsLog($logContent);

            Log::info('Segments analyzed', [
                'total_segments' => count($segments),
                'speech_segments' => count(array_filter($segments, fn($s) => $s->isSpeech())),
                'song_segments' => count(array_filter($segments, fn($s) => $s->isSong())),
            ]);

            return $segments;

        } catch (\Exception $e) {
            Log::error('Failed to analyze segments', [
                'error' => $e->getMessage(),
                'rms_log_path' => $rmsLogPath
            ]);

            throw $e;
        }
    }

    /**
     * @return LivestreamSegment[]
     */
    private function parseRmsLog(string $logContent): array
    {
        $lines = explode("\n", trim($logContent));
        $loudSections = $this->parseAudioSections($logContent);
        
        // Get total duration from the last timestamp
        $totalDuration = 0.0;
        foreach ($lines as $line) {
            if (preg_match('/frame\.pts_time=(\d+\.\d+)/', $line, $matches)) {
                $totalDuration = max($totalDuration, (float) $matches[1]);
            }
        }
        
        $segments = $this->combineLoudAndQuietSections($loudSections, $totalDuration);
        
        return $this->identifySermonCandidate($segments);
    }
    
    private function parseAudioSections(string $logContent, float $threshold = null, float $minSectionDuration = null): array
    {
        $threshold = $threshold ?? $this->rmsThreshold;
        $minSectionDuration = $minSectionDuration ?? $this->minSectionDuration;
        
        $lines = explode("\n", trim($logContent));
        $sections = [];
        $currentSection = null;
        
        foreach ($lines as $line) {
            // Parse both RMS level and accurate timestamp from FFmpeg output
            if (preg_match('/frame\.pts_time=(\d+\.\d+).*lavfi\.astats\.Overall\.RMS_level=(-?\d+\.\d+)/', $line, $matches)) {
                $time = (float) $matches[1]; // Use actual pts_time instead of calculated duration
                $rmsLevel = (float) $matches[2];
                
                if ($rmsLevel > $threshold) {
                    // Start a new loud section if none is active
                    if ($currentSection === null) {
                        $currentSection = ['start' => $time, 'end' => null];
                    }
                } else {
                    // End the current loud section if active
                    if ($currentSection !== null) {
                        $currentSection['end'] = $time;
                        // Only add the section if it meets the minimum duration
                        if (($currentSection['end'] - $currentSection['start']) >= $minSectionDuration) {
                            $sections[] = $currentSection;
                        }
                        $currentSection = null;
                    }
                }
            }
        }
        
        // Close any open section at end of file
        if ($currentSection !== null && isset($time)) {
            $currentSection['end'] = $time;
            if (($currentSection['end'] - $currentSection['start']) >= $minSectionDuration) {
                $sections[] = $currentSection;
            }
        }
        
        return $sections;
    }
    
    private function combineLoudAndQuietSections(array $loudSections, float $totalDuration): array
    {
        $combinedSections = [];
        $previousEnd = 0.0;
        $segmentOrder = 0;
        
        foreach ($loudSections as $section) {
            $start = $section['start'];
            $end = $section['end'];
            
            // Add quiet section before the current loud section
            if ($start > $previousEnd) {
                $combinedSections[] = new LivestreamSegment(
                    startTime: $previousEnd,
                    endTime: $start,
                    duration: $start - $previousEnd,
                    classification: 'speech',
                    avgRms: -40.0, // Typical speech RMS level
                    peakRms: -30.0,
                    segmentOrder: $segmentOrder++
                );
            }
            
            // Add the current loud section
            $combinedSections[] = new LivestreamSegment(
                startTime: $start,
                endTime: $end,
                duration: $end - $start,
                classification: 'song',
                avgRms: -20.0, // Typical song RMS level
                peakRms: -10.0,
                segmentOrder: $segmentOrder++
            );
            
            $previousEnd = $end;
        }
        
        // Add the final quiet section if it exists
        if ($previousEnd < $totalDuration) {
            $combinedSections[] = new LivestreamSegment(
                startTime: $previousEnd,
                endTime: $totalDuration,
                duration: $totalDuration - $previousEnd,
                classification: 'speech',
                avgRms: -40.0,
                peakRms: -30.0,
                segmentOrder: $segmentOrder
            );
        }
        
        return $combinedSections;
    }

    private function finalizeSegment(array $segmentData, int $order): ?LivestreamSegment
    {
        $startTime = $segmentData['start_time'];
        $endTime = end($segmentData['times']);
        $duration = $endTime - $startTime;

        if ($duration < $this->minSectionDuration) {
            return null;
        }

        $rmsValues = $segmentData['rms_values'];
        $avgRms = array_sum($rmsValues) / count($rmsValues);
        $peakRms = max($rmsValues);

        $classification = $avgRms > $this->rmsThreshold ? 'song' : 'speech';

        if ($avgRms < -60.0) {
            $classification = 'silence';
        }

        return new LivestreamSegment(
            startTime: $startTime,
            endTime: $endTime,
            duration: $duration,
            classification: $classification,
            avgRms: $avgRms,
            peakRms: $peakRms,
            segmentOrder: $order,
            metadata: [
                'rms_sample_count' => count($rmsValues),
                'rms_variance' => $this->calculateVariance($rmsValues),
            ]
        );
    }

    /**
     * @param LivestreamSegment[] $segments
     * @return LivestreamSegment[]
     */
    private function identifySermonCandidate(array $segments): array
    {
        $speechSegments = array_filter($segments, fn($s) => $s->isSpeech());
        
        if (empty($speechSegments)) {
            return $segments;
        }

        usort($speechSegments, fn($a, $b) => $b->duration <=> $a->duration);
        $longestSpeechSegment = $speechSegments[0];

        if ($longestSpeechSegment->duration >= $this->minSermonDuration) {
            foreach ($segments as $segment) {
                if ($segment->startTime === $longestSpeechSegment->startTime && 
                    $segment->endTime === $longestSpeechSegment->endTime) {
                    $segment->isSermonCandidate = true;
                    break;
                }
            }
        }

        return $segments;
    }

    private function calculateVariance(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($x) => pow($x - $mean, 2), $values);
        
        return array_sum($squaredDiffs) / count($values);
    }

    public function getVideoMetadata(string $videoPath): array
    {
        try {
            $format = $this->ffprobe->format($videoPath);
            $video = $this->ffprobe->streams($videoPath)->videos()->first();
            
            return [
                'duration' => (float) $format->get('duration'),
                'format_name' => $format->get('format_name'),
                'size' => (int) $format->get('size'),
                'bit_rate' => (int) $format->get('bit_rate'),
                'width' => $video ? $video->get('width') : null,
                'height' => $video ? $video->get('height') : null,
                'codec' => $video ? $video->get('codec_name') : null,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to extract video metadata', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath
            ]);

            throw $e;
        }
    }

    public function validateVideoFile(string $videoPath): bool
    {
        try {
            $format = $this->ffprobe->format($videoPath);
            $formatName = $format->get('format_name');
            $supportedFormats = config('livestream-processing.supported_formats');
            
            foreach ($supportedFormats as $supportedFormat) {
                if (str_contains(strtolower($formatName), strtolower($supportedFormat))) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            Log::warning('Video validation failed', [
                'error' => $e->getMessage(),
                'video_path' => $videoPath
            ]);

            return false;
        }
    }
}