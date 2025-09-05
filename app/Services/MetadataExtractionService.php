<?php

namespace App\Services;

use App\Data\SermonMetadata;
use App\Enums\SermonService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Owenoj\LaravelGetId3\GetId3;

class MetadataExtractionService
{
    public function extractFromUploadedFile(UploadedFile $file): SermonMetadata
    {
        $originalName = $file->getClientOriginalName();
        $filename = $file->hashName();
        $date = $this->extractDateFromFilename($originalName);
        $service = $this->determineServiceFromFile($file);

        // Extract audio metadata using GetID3
        $audioInfo = $this->extractAudioInfo($file);

        return SermonMetadata::create(
            $date,
            $service,
            $filename,
            $originalName,
            $audioInfo['duration'],
            $audioInfo['bitrate'],
            $audioInfo['format'],
            $audioInfo['filesize']
        );
    }

    public function extractDateFromFilename(string $filename): Carbon
    {
        // Remove only the last extension (e.g., .mp3) to preserve dates with dots
        $nameWithoutExtension = preg_replace('/\.[^.]+$/', '', $filename);

        $foundDatePattern = false;

        // ISO format: YYYY-MM-DD (try first as it's most specific)
        if (preg_match('/(\d{4})[_\-\s\.\/](\d{1,2})[_\-\s\.\/](\d{1,2})/', $nameWithoutExtension, $matches)) {
            $foundDatePattern = true;
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            if ($this->isValidDate($year, $month, $day)) {
                return Carbon::createFromDate($year, $month, $day);
            }
        }

        // European format: DD-MM-YYYY (only if ISO didn't match)
        if (! $foundDatePattern && preg_match('/(\d{1,2})[_\-\s\.\/](\d{1,2})[_\-\s\.\/](\d{4})/', $nameWithoutExtension, $matches)) {
            $foundDatePattern = true;
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            if ($this->isValidDate($year, $month, $day)) {
                return Carbon::createFromDate($year, $month, $day);
            }
        }

        // Compact format: YYYYMMDD
        if (! $foundDatePattern && preg_match('/(\d{4})(\d{2})(\d{2})/', $nameWithoutExtension, $matches)) {
            $foundDatePattern = true;
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            if ($this->isValidDate($year, $month, $day)) {
                return Carbon::createFromDate($year, $month, $day);
            }
        }

        // If we found a date pattern but it was invalid, don't try year extraction
        if ($foundDatePattern) {
            return Carbon::today();
        }

        // Year only extraction (only if no date pattern was found)
        if (preg_match('/(\d{4})/', $nameWithoutExtension, $matches)) {
            $year = (int) $matches[1];
            if ($year >= 1900 && $year <= date('Y') + 1) {
                return Carbon::createFromDate($year, (int) date('n'), (int) date('j'));
            }
        }

        // Fallback to current date
        return Carbon::today();
    }

    public function determineServiceFromFile(UploadedFile $file): SermonService
    {
        try {
            $filePath = $file->getRealPath();

            if ($filePath && file_exists($filePath)) {
                $timestamp = filectime($filePath);
                if ($timestamp !== false) {
                    $fileTime = Carbon::createFromTimestamp($timestamp);

                    return $this->determineServiceFromTime($fileTime);
                }
            }
        } catch (\Exception $e) {
            // Fall through to filename-based detection
        }

        return $this->determineServiceFromFilename($file->getClientOriginalName());
    }

    public function determineServiceFromTime(Carbon $time): SermonService
    {
        $hour = $time->hour;

        if ($hour >= 6 && $hour < 14) {
            return SermonService::MORNING;
        }

        return SermonService::EVENING;
    }

    public function determineServiceFromFilename(string $filename): SermonService
    {
        $lowerFilename = strtolower($filename);

        // Morning patterns
        if (
            preg_match('/morning/', $lowerFilename) ||
            preg_match('/[^a-z]am[^a-z]/', $lowerFilename) ||
            preg_match('/^am[^a-z]/', $lowerFilename) ||
            preg_match('/[^a-z]am$/', $lowerFilename) ||
            preg_match('/10[:\-\s]?30/', $lowerFilename) ||
            preg_match('/11[:\-\s]?00/', $lowerFilename)
        ) {
            return SermonService::MORNING;
        }

        // Evening patterns
        if (
            preg_match('/evening/', $lowerFilename) ||
            preg_match('/[^a-z]pm[^a-z]/', $lowerFilename) ||
            preg_match('/^pm[^a-z]/', $lowerFilename) ||
            preg_match('/[^a-z]pm$/', $lowerFilename) ||
            preg_match('/6[:\-\s]?30/', $lowerFilename) ||
            preg_match('/7[:\-\s]?00/', $lowerFilename) ||
            preg_match('/18[:\-\s]?30/', $lowerFilename) ||
            preg_match('/19[:\-\s]?00/', $lowerFilename)
        ) {
            return SermonService::EVENING;
        }

        return SermonService::MORNING;
    }

    public function isValidDate(int $year, int $month, int $day): bool
    {
        if ($year < 1900 || $year > date('Y') + 1) {
            return false;
        }

        if ($month < 1 || $month > 12) {
            return false;
        }

        if ($day < 1 || $day > 31) {
            return false;
        }

        return checkdate($month, $day, $year);
    }

    public function extractFromFilePath(string $filePath): SermonMetadata
    {
        $filename = basename($filePath);
        $date = $this->extractDateFromFilename($filename);
        $service = $this->determineServiceFromFilename($filename);

        // Extract audio info from file path if possible
        $audioInfo = $this->extractAudioInfoFromPath($filePath);

        return SermonMetadata::create(
            $date,
            $service,
            $filename,
            $filename,
            $audioInfo['duration'],
            $audioInfo['bitrate'],
            $audioInfo['format'],
            $audioInfo['filesize']
        );
    }

    /**
     * Extract audio file information using GetID3
     */
    public function extractAudioInfo(UploadedFile $file): array
    {
        try {
            $track = app(GetId3::class, [$file]);
            $info = $track->extractInfo();

            return [
                'duration' => $this->extractDuration($info),
                'bitrate' => $this->extractBitrate($info),
                'format' => $this->extractFormat($info),
                'filesize' => $this->extractFilesize($info, $file),
            ];
        } catch (\Exception $e) {
            // Log the error but don't fail the entire process
            Log::warning('Failed to extract audio info from uploaded file', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return $this->getDefaultAudioInfo($file);
        }
    }

    /**
     * Extract audio file information from file path using GetID3
     */
    public function extractAudioInfoFromPath(string $filePath): array
    {
        try {
            $track = app(GetId3::class, [$filePath]);
            $info = $track->extractInfo();

            return [
                'duration' => $this->extractDuration($info),
                'bitrate' => $this->extractBitrate($info),
                'format' => $this->extractFormat($info),
                'filesize' => $this->extractFilesizeFromPath($info, $filePath),
            ];
        } catch (\Exception $e) {
            // Log the error but don't fail the entire process
            Log::warning('Failed to extract audio info from file path', [
                'filepath' => $filePath,
                'error' => $e->getMessage(),
            ]);

            return $this->getDefaultAudioInfoFromPath($filePath);
        }
    }

    /**
     * Extract duration from GetID3 info array
     */
    private function extractDuration(array $info): ?float
    {
        // Try different possible locations for duration in the GetID3 info array
        if (isset($info['playtime_seconds']) && is_numeric($info['playtime_seconds'])) {
            return (float) $info['playtime_seconds'];
        }

        if (isset($info['audio']['playtime_seconds']) && is_numeric($info['audio']['playtime_seconds'])) {
            return (float) $info['audio']['playtime_seconds'];
        }

        return null;
    }

    /**
     * Extract bitrate from GetID3 info array
     */
    private function extractBitrate(array $info): ?int
    {
        // Try different possible locations for bitrate
        if (isset($info['audio']['bitrate']) && is_numeric($info['audio']['bitrate'])) {
            return (int) $info['audio']['bitrate'];
        }

        if (isset($info['bitrate']) && is_numeric($info['bitrate'])) {
            return (int) $info['bitrate'];
        }

        return null;
    }

    /**
     * Extract format from GetID3 info array
     */
    private function extractFormat(array $info): ?string
    {
        // Try different possible locations for format
        if (isset($info['fileformat']) && is_string($info['fileformat'])) {
            return strtoupper($info['fileformat']);
        }

        if (isset($info['audio']['dataformat']) && is_string($info['audio']['dataformat'])) {
            return strtoupper($info['audio']['dataformat']);
        }

        return null;
    }

    /**
     * Extract filesize from GetID3 info array or UploadedFile
     */
    private function extractFilesize(array $info, UploadedFile $file): ?int
    {
        // Try GetID3 info first
        if (isset($info['filesize']) && is_numeric($info['filesize'])) {
            return (int) $info['filesize'];
        }

        // Fall back to UploadedFile size
        try {
            return $file->getSize();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract filesize from GetID3 info array or file path
     */
    private function extractFilesizeFromPath(array $info, string $filePath): ?int
    {
        // Try GetID3 info first
        if (isset($info['filesize']) && is_numeric($info['filesize'])) {
            return (int) $info['filesize'];
        }

        // Fall back to file system
        try {
            return file_exists($filePath) ? filesize($filePath) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get default audio info when extraction fails
     */
    private function getDefaultAudioInfo(UploadedFile $file): array
    {
        return [
            'duration' => null,
            'bitrate' => null,
            'format' => $this->guessFormatFromExtension($file->getClientOriginalExtension()),
            'filesize' => $file->getSize(),
        ];
    }

    /**
     * Get default audio info from file path when extraction fails
     */
    private function getDefaultAudioInfoFromPath(string $filePath): array
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $filesize = file_exists($filePath) ? filesize($filePath) : null;

        return [
            'duration' => null,
            'bitrate' => null,
            'format' => $this->guessFormatFromExtension($extension),
            'filesize' => $filesize,
        ];
    }

    /**
     * Guess audio format from file extension
     */
    private function guessFormatFromExtension(?string $extension): ?string
    {
        if (! $extension) {
            return null;
        }

        return match (strtolower($extension)) {
            'mp3' => 'MP3',
            'wav' => 'WAV',
            'flac' => 'FLAC',
            'aac' => 'AAC',
            'm4a' => 'M4A',
            'ogg' => 'OGG',
            default => strtoupper($extension),
        };
    }

    /**
     * Validate audio file format and quality
     */
    public function validateAudioFile(UploadedFile $file): array
    {
        $audioInfo = $this->extractAudioInfo($file);
        $errors = [];

        // Check if it's a valid audio format
        $validFormats = ['MP3', 'WAV', 'FLAC', 'AAC', 'M4A'];
        if ($audioInfo['format'] && ! in_array($audioInfo['format'], $validFormats)) {
            $errors[] = "Unsupported audio format: {$audioInfo['format']}. Supported formats: ".implode(', ', $validFormats);
        }

        // Check minimum bitrate for quality (if available)
        if ($audioInfo['bitrate'] && $audioInfo['bitrate'] < 64000) {
            $errors[] = "Audio bitrate too low: {$audioInfo['bitrate']} bps. Minimum recommended: 64 kbps";
        }

        // Check file size limits (max 100MB as per design document)
        $maxSize = 100 * 1024 * 1024; // 100MB in bytes
        if ($audioInfo['filesize'] && $audioInfo['filesize'] > $maxSize) {
            $errors[] = 'File size too large: '.round($audioInfo['filesize'] / 1024 / 1024, 2).'MB. Maximum allowed: 100MB';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'info' => $audioInfo,
        ];
    }
}
