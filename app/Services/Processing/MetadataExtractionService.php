<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Data\SermonMetadata;
use App\Enums\SermonService;
use App\Services\Sermon\SermonFilenameParser;
use App\Traits\SanitizesLogData;
use Carbon\Carbon;
use FFMpeg\FFProbe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Owenoj\LaravelGetId3\GetId3;

/**
 * @see SermonFilenameParser for the pure date/service inference rules this service delegates to.
 */

/**
 * Service for extracting sermon metadata from various media sources.
 *
 * This service implements a multi-layered extraction strategy for dates and
 * church services, using file metadata (FFprobe, GetID3), filename parsing,
 * and client-provided timestamps with cascading fallbacks to ensure metadata
 * is populated even when source data is incomplete.
 *
 * @phpstan-type AudioInfo array{
 *     duration: float|null,
 *     bitrate: int|null,
 *     format: string|null,
 *     filesize: int|null
 * }
 * @phpstan-type Id3Metadata array{
 *     title: string|null,
 *     preacher: string|null,
 *     series: string|null,
 *     reference: string|null
 * }
 * @phpstan-type Id3MetadataExtended array{
 *     title: string|null,
 *     preacher: string|null,
 *     series: string|null,
 *     reference: string|null,
 *     date: string|null,
 *     duration: float|null
 * }
 */
class MetadataExtractionService
{
    use SanitizesLogData;

    public function __construct(
        private readonly SermonFilenameParser $filenameParser = new SermonFilenameParser,
    ) {}

    /**
     * Extract comprehensive sermon metadata from an uploaded file.
     *
     * Combines filename parsing, file modification timestamps, and ID3 tag
     * extraction to build a complete SermonMetadata DTO.
     *
     * @param  UploadedFile  $file  The uploaded audio or video file
     * @return SermonMetadata The extracted metadata record
     */
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

    /**
     * Extract a sermon date from a filename using multiple regex patterns.
     *
     * @param  string  $filename  The filename to parse
     * @return Carbon The extracted date, or Carbon::today() as a final fallback
     */
    public function extractDateFromFilename(string $filename): Carbon
    {
        return $this->filenameParser->extractDateFromFilename($filename);
    }

    /**
     * Determine the sermon service (Morning/Evening) from an uploaded file.
     *
     * First attempts to use the file's modification timestamp (mtime). If the
     * timestamp is unavailable or fails, it falls back to filename patterns.
     *
     * @param  UploadedFile  $file  The uploaded file
     * @return SermonService The identified service
     */
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

    /**
     * Determine the sermon service based on a specific time of day.
     *
     * @param  Carbon  $time  The time to evaluate
     * @return SermonService The identified service
     */
    public function determineServiceFromTime(Carbon $time): SermonService
    {
        return $this->filenameParser->determineServiceFromTime($time);
    }

    /**
     * Determine the sermon service based on filename keywords and patterns.
     *
     * @param  string  $filename  The filename to scan
     * @return SermonService The identified service (defaults to Morning)
     */
    public function determineServiceFromFilename(string $filename): SermonService
    {
        return $this->filenameParser->determineServiceFromFilename($filename);
    }

    /**
     * Verify if the given year, month, and day form a valid calendar date.
     *
     * @param  int  $year  Four-digit year
     * @param  int  $month  Month (1-12)
     * @param  int  $day  Day (1-31)
     * @return bool True if the date is valid and within bounds
     */
    public function isValidDate(int $year, int $month, int $day): bool
    {
        return $this->filenameParser->isValidDate($year, $month, $day);
    }

    /**
     * Extract sermon metadata from a local filesystem path.
     *
     * Similar to extractFromUploadedFile but designed for files already
     * present on the local or S3-compatible filesystem.
     *
     * @param  string  $filePath  The path to the file
     * @return SermonMetadata The extracted metadata record
     */
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
     * Extract audio stream information using GetID3.
     *
     * @param  UploadedFile  $file  The uploaded audio file
     * @return AudioInfo Technical audio metadata
     */
    public function extractAudioInfo(UploadedFile $file): array
    {
        try {
            $track = new GetId3($file);
            $rawInfo = $track->extractInfo();
            $info = is_array($rawInfo) ? $rawInfo : [];

            // getID3 signals file-open failures via an 'error' key rather than throwing
            if (! empty($info['error'])) {
                Log::warning('Failed to extract audio info from uploaded file', $this->sanitizeArrayForLog([
                    'filename' => $file->getClientOriginalName(),
                    'error' => implode('; ', (array) $info['error']),
                ]));

                return $this->getDefaultAudioInfo($file);
            }

            $format = $this->extractFormat($info);

            return [
                'duration' => $this->extractDuration($info),
                'bitrate' => $this->extractBitrate($info),
                'format' => $format ?? $this->guessFormatFromExtension($file->getClientOriginalExtension()),
                'filesize' => $this->extractFilesize($info, $file),
            ];
        } catch (\Exception $e) {
            // Log the error but don't fail the entire process
            Log::warning('Failed to extract audio info from uploaded file', $this->sanitizeArrayForLog([
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]));

            return $this->getDefaultAudioInfo($file);
        }
    }

    /**
     * Extract audio stream information from a file path using GetID3.
     *
     * @param  string  $filePath  Path to the audio file
     * @return AudioInfo Technical audio metadata
     */
    public function extractAudioInfoFromPath(string $filePath): array
    {
        try {
            $track = new GetId3($filePath);
            $rawInfo = $track->extractInfo();
            $info = is_array($rawInfo) ? $rawInfo : [];

            $format = $this->extractFormat($info);

            return [
                'duration' => $this->extractDuration($info),
                'bitrate' => $this->extractBitrate($info),
                'format' => $format ?? $this->guessFormatFromExtension(pathinfo($filePath, PATHINFO_EXTENSION)),
                'filesize' => $this->extractFilesizeFromPath($info, $filePath),
            ];
        } catch (\Exception $e) {
            // Log the error but don't fail the entire process
            Log::warning('Failed to extract audio info from file path', $this->sanitizeArrayForLog([
                'filepath' => $filePath,
                'error' => $e->getMessage(),
            ]));

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
     * @return array{duration: float|null, bitrate: int|null, format: string|null, filesize: int|null}
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
     * @return array{duration: float|null, bitrate: int|null, format: string|null, filesize: int|null}
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
     * Extract date from video file metadata (creation date) with fallbacks.
     *
     * Cascading date extraction strategy:
     * 1. Video metadata creation_time tag: Primary source of truth for original
     *    capture time. Extracted via FFprobe.
     * 2. Filename parsing: Common for church recordings (e.g., "2024-01-15_morning.mp4").
     *    Wins over metadata if the metadata date is newer than the filename date,
     *    suggesting the metadata reflects a download/re-encode time.
     * 3. Client-provided file date: Fallback for browser uploads where JS
     *    provides the File.lastModified date (YYYY-MM-DD format).
     * 4. File timestamp: Fallback for non-HTTP uploads using the OS filemtime.
     * 5. Today's date: Final fallback to prevent processing failure.
     *
     * @param  UploadedFile|string  $file  UploadedFile or absolute file path
     * @param  string|null  $clientProvidedDate  Date provided from client-side JavaScript (YYYY-MM-DD format, File.lastModified)
     * @return Carbon The extracted date
     */
    public function extractDateFromVideo(UploadedFile|string $file, ?string $clientProvidedDate = null): Carbon
    {
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);
        $clientDate = $this->parseClientProvidedDate($clientProvidedDate, $filename);

        try {
            $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
            $filenameDate = $this->filenameParser->tryExtractDateFromFilename($filename);

            if (! $filePath || ! file_exists($filePath)) {
                Log::warning('Video file not found for date extraction, using available fallbacks', $this->sanitizeArrayForLog([
                    'file_path' => is_string($filePath) ? $filePath : null,
                    'filename' => $filename,
                ]));

                return $filenameDate ?? $clientDate ?? $this->extractDateFromFilename($filename);
            }

            // Strategy 1: Video metadata (FFprobe)
            $metadataDate = $this->extractDateFromVideoMetadata($filePath);

            if ($metadataDate !== null) {
                // If the filename encodes a real date that is older than the metadata date,
                // the metadata is likely a download/re-encode timestamp — prefer the filename.
                if ($filenameDate !== null && $metadataDate->isAfter($filenameDate->copy()->endOfDay())) {
                    Log::info('Filename date preferred: metadata creation_time is newer', $this->sanitizeArrayForLog([
                        'filename' => $filename,
                        'filename_date' => $filenameDate->toDateString(),
                        'metadata_date' => $metadataDate->toDateString(),
                    ]));

                    return $filenameDate;
                }

                Log::info('Extracted creation date from video metadata tags', $this->sanitizeArrayForLog([
                    'filename' => $filename,
                    'metadata_date' => $metadataDate->toDateString(),
                ]));

                return $metadataDate;
            }

            // Strategy 2: Filename parsing
            if ($filenameDate !== null) {
                Log::info('Using filename date for video date extraction', $this->sanitizeArrayForLog([
                    'filename' => $filename,
                    'filename_date' => $filenameDate->toDateString(),
                ]));

                return $filenameDate;
            }

            // Strategy 3: Client-provided file date
            if ($clientDate !== null) {
                Log::info('Using client-provided file modification date', $this->sanitizeArrayForLog([
                    'filename' => $filename,
                    'parsed_date' => $clientDate->toDateString(),
                ]));

                return $clientDate;
            }

            // Strategy 4: File modification timestamp
            $fileTimestampDate = $this->extractDateFromFileTimestamp($filePath);

            if ($fileTimestampDate !== null) {
                Log::info('Using original file modification timestamp', $this->sanitizeArrayForLog([
                    'filename' => $filename,
                    'file_date' => $fileTimestampDate->toDateString(),
                ]));

                return $fileTimestampDate;
            }

            Log::info('No creation date in video metadata or file timestamp, falling back to filename', $this->sanitizeArrayForLog([
                'filename' => $filename,
            ]));

        } catch (\Exception $e) {
            Log::warning('Failed to extract date from video metadata, using filename fallback', $this->sanitizeArrayForLog([
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]));
        }

        // Final fallback to filename extraction
        return $this->filenameParser->tryExtractDateFromFilename($filename) ?? $clientDate ?? $this->extractDateFromFilename($filename);
    }

    /**
     * Extract creation date from video metadata using FFprobe.
     */
    private function extractDateFromVideoMetadata(string $filePath): ?Carbon
    {
        try {
            $ffprobe = FFProbe::create([
                'ffmpeg.binaries' => config('media-processing.ffmpeg.ffmpeg_path'),
                'ffprobe.binaries' => config('media-processing.ffmpeg.ffprobe_path'),
            ]);

            $format = $ffprobe->format($filePath);
            $tags = $format->get('tags');

            if ($tags && isset($tags['creation_time'])) {
                return Carbon::parse($tags['creation_time']);
            }
        } catch (\Exception $e) {
            // Silently fail, cascading to next strategy
        }

        return null;
    }

    /**
     * Extract date from the file's modification timestamp if it differs significantly from now.
     */
    private function extractDateFromFileTimestamp(string $filePath): ?Carbon
    {
        $mtime = filemtime($filePath);

        if ($mtime === false) {
            return null;
        }

        $fileDate = Carbon::createFromTimestamp($mtime);

        // Only use file timestamp if it's reasonable (not just uploaded seconds ago)
        // and different from current date by at least one full day.
        if (abs($fileDate->diffInDays(Carbon::now())) >= 1) {
            return $fileDate;
        }

        return null;
    }

    private function parseClientProvidedDate(?string $clientProvidedDate, string $filename): ?Carbon
    {
        if (! filled($clientProvidedDate)) {
            return null;
        }

        try {
            return Carbon::parse($clientProvidedDate);
        } catch (\Exception $e) {
            Log::warning('Failed to parse client-provided date, falling back', $this->sanitizeArrayForLog([
                'filename' => $filename,
                'client_date' => (string) $clientProvidedDate,
                'error' => $e->getMessage(),
            ]));

            return null;
        }
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
            if (Storage::disk($sermonDisk)->exists($filePath)) {
                return Storage::disk($sermonDisk)->size($filePath);
            }

            $tempDisk = config('media-processing.storage.temp_disk', 'local');
            if (Storage::disk($tempDisk)->exists($filePath)) {
                return Storage::disk($tempDisk)->size($filePath);
            }

            // Try public disk as fallback
            if (Storage::disk('public')->exists($filePath)) {
                return Storage::disk('public')->size($filePath);
            }
        } catch (\Exception $e) {
            Log::debug('Failed to get file size from storage', $this->sanitizeArrayForLog([
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]));
        }

        // Final fallback - might be a local path without leading slash
        if (! file_exists($filePath)) {
            return null;
        }

        $fileSize = filesize($filePath);

        return $fileSize === false ? null : $fileSize;
    }

    /**
     * Extract ID3 metadata tags from audio file (title, artist/preacher, album/series, reference).
     *
     * @param  UploadedFile  $file  The uploaded audio file
     * @return Id3Metadata
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

            Log::info('Extracted ID3 metadata from audio file', $this->sanitizeArrayForLog([
                'filename' => $file->getClientOriginalName(),
                'title' => (string) $title,
                'preacher' => (string) $preacher,
                'series' => (string) $series,
                'reference' => (string) $reference,
                'title_type' => gettype($title),
                'title_empty' => empty($title),
                'raw_info_keys' => array_keys($info),
            ]));

            return [
                'title' => $title ?: null,
                'preacher' => $preacher ?: null,
                'series' => $series ?: null,
                'reference' => $reference,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to extract ID3 metadata from audio file', $this->sanitizeArrayForLog([
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]));

            return [
                'title' => null,
                'preacher' => null,
                'series' => null,
                'reference' => null,
            ];
        }
    }

    /**
     * Extract explicitly embedded audio metadata from a filesystem path.
     *
     * @param  string  $filePath  Path to the audio file
     * @return Id3MetadataExtended
     */
    public function extractId3MetadataFromPath(string $filePath): array
    {
        try {
            $track = new GetId3($filePath);
            $rawInfo = $track->extractInfo();
            $info = is_array($rawInfo) ? $rawInfo : [];
            $comments = isset($info['comments']) && is_array($info['comments'])
                ? $info['comments']
                : [];

            return [
                'title' => $this->firstCommentValue($comments, 'title'),
                'preacher' => $this->firstCommentValue($comments, 'artist'),
                'series' => $this->firstCommentValue($comments, 'album'),
                'reference' => $this->firstCommentValue($comments, 'comment'),
                'date' => $this->firstCommentValue($comments, 'date') ?? $this->firstCommentValue($comments, 'year'),
                'duration' => $this->extractDuration($info),
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to extract ID3 metadata from audio file path', $this->sanitizeArrayForLog([
                'filepath' => $filePath,
                'error' => $e->getMessage(),
            ]));

            return [
                'title' => null,
                'preacher' => null,
                'series' => null,
                'reference' => null,
                'date' => null,
                'duration' => null,
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
        return filled($value) && is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $comments
     */
    private function firstCommentValue(array $comments, string $key): ?string
    {
        $values = $comments[$key] ?? null;

        if (! is_array($values)) {
            return null;
        }

        $value = $values[0] ?? null;

        return filled($value) ? trim((string) $value) : null;
    }
}
