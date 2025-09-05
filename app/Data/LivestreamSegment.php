<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class LivestreamSegment extends Data
{
    public function __construct(
        public float $startTime,
        public float $endTime,
        public float $duration,
        public string $classification,
        public float $avgRms,
        public float $peakRms,
        public bool $isSermonCandidate = false,
        public int $segmentOrder = 0,
        public ?array $metadata = null,
    ) {}

    public function getDurationInMinutes(): float
    {
        return round($this->duration / 60, 2);
    }

    public function getStartTimeFormatted(): string
    {
        return $this->formatTime($this->startTime);
    }

    public function getEndTimeFormatted(): string
    {
        return $this->formatTime($this->endTime);
    }

    public function getDurationFormatted(): string
    {
        return $this->formatDuration($this->duration);
    }

    public function isSpeech(): bool
    {
        return $this->classification === 'speech';
    }

    public function isSong(): bool
    {
        return $this->classification === 'song';
    }

    public function isSilence(): bool
    {
        return $this->classification === 'silence';
    }

    private function formatTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remainingSeconds = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
        }

        return sprintf('%02d:%02d', $minutes, $remainingSeconds);
    }

    private function formatDuration(float $seconds): string
    {
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes > 60) {
            $hours = floor($minutes / 60);
            $minutes = $minutes % 60;

            return sprintf('%dh %dm %ds', $hours, $minutes, $remainingSeconds);
        }

        return sprintf('%dm %ds', $minutes, $remainingSeconds);
    }
}
