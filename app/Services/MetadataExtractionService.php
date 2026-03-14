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
            $this->toNullableFloat($audioInfo['duration'] ?? null),
            $this->toNullableInt($audioInfo['bitrate'] ?? null),
            $this->toNullableString($audioInfo['format'] ?? null),
            $this->toNullableInt($audioInfo['filesize'] ?? null)
        );
    }

    public function extractDateFromFilename(string $filename): Carbon
    {
        // Remove only the last extension (e.g., .mp3) to preserve dates with dots
        $nameWithoutExtension = preg_replace('/\.[^.]+$/', '', $filename) ?? $filename;

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

    /**
     * Like extractDateFromFilename() but returns null when no recognisable date pattern is found,
     * rather than falling back to Carbon::today(). Used for date-comparison logic where the
     * absence of a date must be distinguishable from a real extracted date.
     */
    private function tryExtractDateFromFilename(string $filename): ?Carbon
    {
        $nameWithoutExtension = preg_replace('/\.[^.]+$/', '', $filename) ?? $filename;

        // ISO format: YYYY-MM-DD
        if (preg_match('/(\d{4})[_\-\s\.\/](\d{1,2})[_\-\s\.\/](\d{1,2})/', $nameWithoutExtension, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            if ($this->isValidDate($year, $month, $day)) {
                return Carbon::createFromDate($year, $month, $day);
            }

            return null;
        }

        // European format: DD-MM-YYYY
        if (preg_match('/(\d{1,2})[_\-\s\.\/](\d{1,2})[_\-\s\.\/](\d{4})/', $nameWithoutExtension, $matches)) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            if ($this->isValidDate($year, $month, $day)) {
                return Carbon::createFromDate($year, $month, $day);
            }

            return null;
        }

        // Compact format: YYYYMMDD
        if (preg_match('/(\d{4})(\d{2})(\d{2})/', $nameWithoutExtension, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            if ($this->isValidDate($year, $month, $day)) {
                return Carbon::createFromDate($year, $month, $day);
            }

            return null;
        }

        return null;
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

        // Try to extract time in HH-MM or HH:MM format (e.g., "18-07.mkv" or "10:30.mp3")
        // Look for patterns like: 18-07, 18:07, 1807 (two digits, separator optional, two digits)
        if (preg_match('/(\d{1,2})[-:\.](\d{2})/', $filename, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            // Validate it's a reasonable time (hour 0-23, minute 0-59)
            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                // Use the same 2 PM (14:00) cutoff
                if ($hour < 14) {
                    return SermonService::MORNING;
                } else {
                    return SermonService::EVENING;
                }
            }
        }

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
            $this->toNullableFloat($audioInfo['duration'] ?? null),
            $this->toNullableInt($audioInfo['bitrate'] ?? null),
            $this->toNullableString($audioInfo['format'] ?? null),
            $this->toNullableInt($audioInfo['filesize'] ?? null)
        );
    }

    /**
     * Extract audio file information using GetID3
     *
     * @return array<string, float|int|string|null>
     */
    public function extractAudioInfo(UploadedFile $file): array
    {
        try {
            $track = new GetId3($file);
            $rawInfo = $track->extractInfo();
            $info = is_array($rawInfo) ? $rawInfo : [];

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
     *
     * @return array<string, float|int|string|null>
     */
    public function extractAudioInfoFromPath(string $filePath): array
    {
        try {
            $track = new GetId3($filePath);
            $rawInfo = $track->extractInfo();
            $info = is_array($rawInfo) ? $rawInfo : [];

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
     *
     * @param  array<string, mixed>  $info
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
     *
     * @param  array<string, mixed>  $info
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
     *
     * @param  array<string, mixed>  $info
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
     *
     * @param  array<string, mixed>  $info
     */
    private function extractFilesize(array $info, UploadedFile $file): ?int
    {
        // Try GetID3 info first (but only if it's a valid non-zero size)
        if (isset($info['filesize']) && is_numeric($info['filesize']) && $info['filesize'] > 0) {
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
     *
     * @param  array<string, mixed>  $info
     */
    private function extractFilesizeFromPath(array $info, string $filePath): ?int
    {
        // Try GetID3 info first
        if (isset($info['filesize']) && is_numeric($info['filesize'])) {
            return (int) $info['filesize'];
        }

        // Fall back to file system (S3-aware)
        try {
            return $this->getFileSize($filePath);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get default audio info when extraction fails
     *
     * @return array<string, float|int|string|null>
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
     *
     * @return array<string, float|int|string|null>
     */
    private function getDefaultAudioInfoFromPath(string $filePath): array
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $filesize = $this->getFileSize($filePath);

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
     *
     * @return array<string, bool|array<int, string>|array<string, float|int|string|null>>
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
        $fileSize = $this->toNullableFloat($audioInfo['filesize'] ?? null);
        if ($fileSize !== null && $fileSize > $maxSize) {
            $errors[] = 'File size too large: '.round($fileSize / 1024 / 1024, 2).'MB. Maximum allowed: 100MB';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'info' => $audioInfo,
        ];
    }

    /**
     * Extract date from video file metadata (creation date) with fallback to filename
     *
     * Cascading date extraction strategy:
     * 1. Client-provided file date (from browser's File.lastModified API)
     * 2. Video metadata creation_time tag (from FFprobe)
     * 3. File timestamp (for non-HTTP uploads)
     * 4. Filename parsing
     * 5. Today's date (final fallback)
     *
     * @param  UploadedFile|string  $file  UploadedFile or absolute file path
     * @param  string|null  $clientProvidedDate  Date provided from client-side JavaScript (YYYY-MM-DD format)
     * @return Carbon The extracted date
     */
    public function extractDateFromVideo(UploadedFile|string $file, ?string $clientProvidedDate = null): Carbon
    {
        try {
            // Get file path - handle both UploadedFile and string path
            $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
            $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);

            // Strategy 1: Use client-provided file date (from JavaScript extraction of File.lastModified)
            if ($clientProvidedDate) {
                try {
                    $parsedDate = Carbon::parse($clientProvidedDate);

                    Log::info('Using client-provided file modification date', [
                        'filename' => $filename,
                        'client_date' => $clientProvidedDate,
                        'parsed_date' => $parsedDate->toDateString(),
                    ]);

                    return $parsedDate;
                } catch (\Exception $e) {
                    Log::warning('Failed to parse client-provided date, falling back', [
                        'client_date' => $clientProvidedDate,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! $filePath || ! file_exists($filePath)) {
                Log::warning('Video file not found for date extraction, using filename', [
                    'file_path' => $filePath,
                    'filename' => $filename,
                ]);

                return $this->extractDateFromFilename($filename);
            }

            // Strategy 2: Try to extract creation date from video metadata using FFprobe
            $ffprobe = \FFMpeg\FFProbe::create([
                'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);

            $format = $ffprobe->format($filePath);

            // Try to get creation_time from video metadata
            // This is typically set by video recording devices
            $tags = $format->get('tags');
            if ($tags && isset($tags['creation_time'])) {
                $creationTime = $tags['creation_time'];
                $metadataDate = Carbon::parse($creationTime);
                $filenameDate = $this->tryExtractDateFromFilename($filename);

                // If the filename encodes a real date that is older than the metadata date,
                // the metadata is likely a download/re-encode timestamp — prefer the filename.
                if ($filenameDate !== null && $metadataDate->isAfter($filenameDate->copy()->endOfDay())) {
                    Log::info('Filename date preferred: metadata creation_time is newer', [
                        'filename' => $filename,
                        'filename_date' => $filenameDate->toDateString(),
                        'metadata_date' => $metadataDate->toDateString(),
                    ]);

                    return $filenameDate;
                }

                Log::info('Extracted creation date from video metadata tags', [
                    'filename' => $filename,
                    'creation_time' => $creationTime,
                ]);

                return $metadataDate;
            }

            // Strategy 2: For UploadedFile, check the original file's modification time
            // This preserves the date from the user's filesystem before Laravel stores it
            if ($file instanceof UploadedFile) {
                $originalMtime = filemtime($filePath);
                if ($originalMtime !== false) {
                    $fileDate = Carbon::createFromTimestamp($originalMtime);

                    // Only use file timestamp if it's reasonable (not just uploaded seconds ago)
                    // and different from current date by more than a day
                    $daysDiff = abs($fileDate->diffInDays(Carbon::now()));
                    if ($daysDiff >= 1) {
                        Log::info('Using original file modification timestamp', [
                            'filename' => $filename,
                            'file_date' => $fileDate->toDateString(),
                            'days_difference' => $daysDiff,
                        ]);

                        return $fileDate;
                    }
                }
            }

            Log::info('No creation date in video metadata or file timestamp, falling back to filename', [
                'filename' => $filename,
                'available_tags' => $tags ? array_keys($tags) : [],
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to extract date from video metadata, using filename fallback', [
                'filename' => $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file),
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to filename extraction
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);

        return $this->extractDateFromFilename($filename);
    }

    /**
     * Get file size (S3-aware) - handles both local paths and storage paths
     */
    private function getFileSize(string $filePath): ?int
    {
        // For local file paths, use filesize
        if (str_starts_with($filePath, '/') || str_contains($filePath, ':\\')) {
            if (! file_exists($filePath)) {
                return null;
            }

            $fileSize = filesize($filePath);

            return $fileSize === false ? null : $fileSize;
        }

        // For storage paths, check if it's a storage-based path
        try {
            // Try to determine which disk this might be on
            $sermonDisk = config('media-processing.storage.sermon_disk', 'public');
            if (\Illuminate\Support\Facades\Storage::disk($sermonDisk)->exists($filePath)) {
                return \Illuminate\Support\Facades\Storage::disk($sermonDisk)->size($filePath);
            }

            $tempDisk = config('media-processing.storage.temp_disk', 'local');
            if (\Illuminate\Support\Facades\Storage::disk($tempDisk)->exists($filePath)) {
                return \Illuminate\Support\Facades\Storage::disk($tempDisk)->size($filePath);
            }

            // Try public disk as fallback
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($filePath)) {
                return \Illuminate\Support\Facades\Storage::disk('public')->size($filePath);
            }
        } catch (\Exception $e) {
            Log::debug('Failed to get file size from storage', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }

        // Final fallback - might be a local path without leading slash
        if (! file_exists($filePath)) {
            return null;
        }

        $fileSize = filesize($filePath);

        return $fileSize === false ? null : $fileSize;
    }

    /**
     * Extract ID3 metadata tags from audio file (title, artist/preacher, album/series, reference)
     *
     * @param  UploadedFile  $file  The uploaded audio file
     * @return array{title: string|null, preacher: string|null, series: string|null, reference: string|null}
     */
    public function extractId3Metadata(UploadedFile $file): array
    {
        try {
            $track = new GetId3($file);
            $rawInfo = $track->extractInfo();
            $info = is_array($rawInfo) ? $rawInfo : [];

            $title = $track->getTitle();
            $preacher = $track->getArtist();
            $series = $track->getAlbum();

            // Reference is typically stored in comments
            $reference = null;
            if (isset($info['comments']['comment'][0])) {
                $reference = $info['comments']['comment'][0];
            }

            Log::info('Extracted ID3 metadata from audio file', [
                'filename' => $file->getClientOriginalName(),
                'title' => $title,
                'preacher' => $preacher,
                'series' => $series,
                'reference' => $reference,
                'title_type' => gettype($title),
                'title_empty' => empty($title),
                'raw_info_keys' => array_keys($info),
            ]);

            return [
                'title' => $title ?: null,
                'preacher' => $preacher ?: null,
                'series' => $series ?: null,
                'reference' => $reference,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to extract ID3 metadata from audio file', [
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return [
                'title' => null,
                'preacher' => null,
                'series' => null,
                'reference' => null,
            ];
        }
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function toNullableString(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
