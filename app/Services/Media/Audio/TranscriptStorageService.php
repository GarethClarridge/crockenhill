<?php

declare(strict_types=1);

namespace App\Services\Media\Audio;

use App\Traits\SanitizesLogData;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TranscriptStorageService
{
    use SanitizesLogData;

    private const TRANSCRIPT_DIRECTORY = 'transcripts';

    /** @var array<int, string>|null */
    private ?array $transcriptReadDisks = null;

    /**
     * Store transcript content to file
     *
     * @param  int  $sermonId  The sermon ID
     * @param  string  $transcript  The transcript content
     * @return string The file path where transcript was stored
     *
     * @throws Exception When storage fails
     */
    public function storeTranscript(int $sermonId, string $transcript): string
    {
        $filePath = self::TRANSCRIPT_DIRECTORY.'/'.$this->getTranscriptFilename($sermonId);
        $directory = self::TRANSCRIPT_DIRECTORY;
        $disk = $this->transcriptDisk();
        $storage = Storage::disk($disk);

        try {
            // Ensure transcript directory exists
            if (! $storage->exists($directory)) {
                $storage->makeDirectory($directory);
                Log::info('Created transcript directory', $this->sanitizeArrayForLog([
                    'directory' => $directory,
                    'disk' => $disk,
                ]));
            }

            // Store the transcript
            $success = $storage->put($filePath, $transcript);

            if (! $success) {
                throw new Exception('Failed to write transcript to storage');
            }

            Log::info('Transcript stored successfully', $this->sanitizeArrayForLog([
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'size' => strlen($transcript),
            ]));

            return $filePath;
        } catch (Exception $e) {
            Log::error('Failed to store transcript', $this->sanitizeArrayForLog([
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));
            throw new Exception("Failed to store transcript for sermon {$sermonId}: ".$e->getMessage());
        }
    }

    /**
     * Retrieve transcript content from storage
     *
     * @param  int  $sermonId  The sermon ID
     * @return string|null The transcript content or null if not found
     */
    public function getTranscript(int $sermonId): ?string
    {
        $filename = $this->getTranscriptFilename($sermonId);
        $filePath = self::TRANSCRIPT_DIRECTORY.'/'.$filename;
        $disk = $this->transcriptDisk();
        $storage = Storage::disk($disk);

        if (! $storage->exists($filePath)) {
            Log::info('Transcript file not found', $this->sanitizeArrayForLog([
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
            ]));

            return null;
        }

        try {
            $content = $storage->get($filePath);

            if (! is_string($content)) {
                return null;
            }

            Log::info('Transcript retrieved successfully', $this->sanitizeArrayForLog([
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'size' => strlen($content),
            ]));

            return $content;
        } catch (Exception $e) {
            Log::error('Failed to retrieve transcript', $this->sanitizeArrayForLog([
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            return null;
        }
    }

    /**
     * Check if transcript exists for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return bool True if transcript exists
     */
    public function transcriptExists(int $sermonId): bool
    {
        $filename = $this->getTranscriptFilename($sermonId);
        $filePath = self::TRANSCRIPT_DIRECTORY.'/'.$filename;

        return Storage::disk($this->transcriptDisk())->exists($filePath);
    }

    /**
     * Delete transcript file for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return bool True if deleted or didn't exist
     */
    public function deleteTranscript(int $sermonId): bool
    {
        $filename = $this->getTranscriptFilename($sermonId);
        $filePath = self::TRANSCRIPT_DIRECTORY.'/'.$filename;
        $disk = $this->transcriptDisk();
        $storage = Storage::disk($disk);

        if (! $storage->exists($filePath)) {
            Log::info('Transcript file does not exist, nothing to delete', $this->sanitizeArrayForLog([
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
            ]));

            return true;
        }

        try {
            $success = $storage->delete($filePath);

            if ($success) {
                Log::info('Transcript deleted successfully', $this->sanitizeArrayForLog([
                    'sermon_id' => $sermonId,
                    'file_path' => $filePath,
                    'disk' => $disk,
                ]));
            } else {
                Log::warning('Failed to delete transcript file', $this->sanitizeArrayForLog([
                    'sermon_id' => $sermonId,
                    'file_path' => $filePath,
                    'disk' => $disk,
                ]));
            }

            return $success;
        } catch (Exception $e) {
            Log::error('Error deleting transcript', $this->sanitizeArrayForLog([
                'sermon_id' => $sermonId,
                'file_path' => $filePath,
                'disk' => $disk,
                'error' => $e->getMessage(),
                'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
            ]));

            return false;
        }
    }

    /**
     * Clean up transcript files on processing failure
     *
     * @param  int  $sermonId  The sermon ID
     */
    public function cleanupOnFailure(int $sermonId): void
    {
        Log::info('Cleaning up transcript files after processing failure', $this->sanitizeArrayForLog([
            'sermon_id' => $sermonId,
        ]));

        $this->deleteTranscript($sermonId);
    }

    /**
     * Get the transcript filename for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return string The filename
     */
    private function getTranscriptFilename(int $sermonId): string
    {
        return "sermon_{$sermonId}.md";
    }

    /**
     * Get the full transcript file path for a sermon
     *
     * @param  int  $sermonId  The sermon ID
     * @return string The full file path
     */
    public function getTranscriptPath(int $sermonId): string
    {
        $filename = $this->getTranscriptFilename($sermonId);

        return self::TRANSCRIPT_DIRECTORY.'/'.$filename;
    }

    /**
     * Read transcript content from a given path, trying all candidate read disks in priority order.
     *
     * @param  string  $transcriptPath  The storage path to the transcript file
     * @return string|null The transcript content, or null if not found on any disk
     */
    public function readTranscriptFromPath(string $transcriptPath): ?string
    {
        $path = trim($transcriptPath);

        if ($path === '') {
            return null;
        }

        foreach ($this->getTranscriptReadDisks() as $disk) {
            try {
                $storage = Storage::disk($disk);

                if (! $storage->exists($path)) {
                    continue;
                }

                $content = $storage->get($path);

                return is_string($content) ? $content : null;
            } catch (Exception $e) {
                Log::warning('Failed to read transcript from disk', $this->sanitizeArrayForLog([
                    'disk' => $disk,
                    'transcript_file_path' => $path,
                    'error' => $e->getMessage(),
                    'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
                ]));
            }
        }

        return null;
    }

    /**
     * Get the ordered list of disks to try when reading a transcript.
     *
     * Performance Optimization: Memoize the list of disks to avoid redundant config() calls
     * and array operations during high-frequency transcript lookups.
     *
     * @return array<int, string>
     */
    public function getTranscriptReadDisks(): array
    {
        if ($this->transcriptReadDisks !== null) {
            return $this->transcriptReadDisks;
        }

        $transcriptDisk = (string) config('media-processing.storage.transcript_disk', '');
        $sermonDisk = (string) config('media-processing.storage.sermon_disk', '');
        $defaultDisk = (string) config('filesystems.default', '');

        $diskCandidates = [
            $transcriptDisk,
            $sermonDisk,
            $defaultDisk,
            'local',
            'public',
        ];

        if (is_string(config('filesystems.disks.do_spaces.bucket')) && config('filesystems.disks.do_spaces.bucket') !== '') {
            $diskCandidates[] = 'do_spaces';
        }

        $this->transcriptReadDisks = array_values(array_filter(array_unique($diskCandidates), fn (string $disk): bool => $disk !== ''));

        return $this->transcriptReadDisks;
    }

    private function transcriptDisk(): string
    {
        return (string) config('media-processing.storage.transcript_disk', config('media-processing.storage.sermon_disk', config('filesystems.default', 'public')));
    }
}
