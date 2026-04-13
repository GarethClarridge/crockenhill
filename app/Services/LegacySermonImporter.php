<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ProcessingId3Metadata;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class LegacySermonImporter
{
    public function __construct(
        private readonly ProcessingRunOrchestrator $orchestrator,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $csvIndex
     * @param  \Closure(string $filename, string $result): void|null  $onProgress
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function import(
        string $directory,
        array $csvIndex,
        bool $dryRun,
        int $delay,
        bool $force,
        ?\Closure $onProgress = null,
    ): array {
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($this->discoverMp3Files($directory) as $absolutePath) {
            $filename = basename($absolutePath);

            try {
                $result = $this->importFile(
                    absolutePath: $absolutePath,
                    filename: $filename,
                    csvIndex: $csvIndex,
                    dryRun: $dryRun,
                    force: $force,
                );

                if ($result === 'skipped') {
                    $skipped++;
                } else {
                    $imported++;
                }
            } catch (\Throwable $e) {
                Log::error('Legacy sermon import failed for file', [
                    'file' => $filename,
                    'error' => $e->getMessage(),
                ]);
                $result = 'error';
                $errors++;
            }

            if ($onProgress !== null) {
                $onProgress($filename, $result);
            }

            if (! $dryRun && $delay > 0) {
                sleep($delay);
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param  array<string, array<string, mixed>>  $csvIndex
     */
    private function importFile(
        string $absolutePath,
        string $filename,
        array $csvIndex,
        bool $dryRun,
        bool $force,
    ): string {
        $fileHash = hash_file('sha256', $absolutePath);
        $fileSize = filesize($absolutePath);

        if ($fileHash === false || $fileSize === false) {
            throw new \RuntimeException("Could not read file: {$absolutePath}");
        }

        if (! $force) {
            if ($this->isDuplicateByHash($fileHash)) {
                return 'skipped';
            }

            if ($this->isDuplicateByFilename($filename)) {
                return 'skipped';
            }
        }

        if ($dryRun) {
            return 'imported';
        }

        $csvRow = $csvIndex[$this->normaliseTapeId($filename)] ?? null;

        $date = $this->extractDate($csvRow);
        $storedPath = $this->storeFile($absolutePath, $filename, $date);

        $processingLog = MediaProcessingLog::create([
            'processing_id' => (string) Str::uuid(),
            'processing_type' => MediaType::Audio,
            'original_filename' => $filename,
            'file_hash' => $fileHash,
            'file_size' => $fileSize,
            'source_file_path' => $storedPath,
            'status' => ProcessingStatus::Pending,
            'current_step' => 'audio_processing_initiated',
            'extracted_date' => $date,
            'extracted_service' => $this->extractService($csvRow),
            'duration' => $this->extractDuration($csvRow),
            'processing_metadata' => $this->buildProcessingMetadata($csvRow),
        ]);

        $this->orchestrator->start($processingLog);

        return 'imported';
    }

    /** @return list<string> */
    private function discoverMp3Files(string $directory): array
    {
        $base = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        // Use GLOB_BRACE for case-insensitive matching on Linux production (legacy Windows rips
        // often have uppercase .MP3 extensions that glob('*.mp3') would miss on ext4).
        $files = glob($base.'*.{mp3,MP3,Mp3}', GLOB_BRACE);

        return $files === false ? [] : array_values(array_unique($files));
    }

    private function isDuplicateByHash(string $fileHash): bool
    {
        // Intentionally includes Completed — unlike the upload flow, which only guards against
        // in-flight duplicates, the import must also skip files that already finished processing
        // to prevent creating duplicate sermon records from the same legacy tape.
        return MediaProcessingLog::query()
            ->where('file_hash', $fileHash)
            ->whereIn('status', [
                ProcessingStatus::Pending->value,
                ProcessingStatus::Processing->value,
                ProcessingStatus::Completed->value,
            ])
            ->exists();
    }

    private function isDuplicateByFilename(string $filename): bool
    {
        // Same reasoning as isDuplicateByHash — historical duplicates matter for imports.
        return MediaProcessingLog::query()
            ->where('original_filename', $filename)
            ->whereIn('status', [
                ProcessingStatus::Pending->value,
                ProcessingStatus::Processing->value,
                ProcessingStatus::Completed->value,
            ])
            ->exists();
    }

    /**
     * Strip the tape annotation (#...#) from the filename to get the bare tape ID for CSV lookup.
     * Example: "ABC123 #extra#.mp3" -> "ABC123"
     */
    private function normaliseTapeId(string $filename): string
    {
        $withoutExtension = pathinfo($filename, PATHINFO_FILENAME);

        return trim((string) preg_replace('/#[^#]*#/', '', $withoutExtension));
    }

    private function storeFile(string $absolutePath, string $filename, ?Carbon $date): string
    {
        $disk = config('media-processing.storage.sermon_disk', 'public');
        // Default matches UnifiedMediaProcessor::storeAudioFile; actual config value is 'sermons/audio'
        $basePath = config('media-processing.storage.paths.audio', 'sermons/audio');

        $year = $date?->format('Y') ?? now()->format('Y');
        $month = $date?->format('m') ?? now()->format('m');

        $directory = "{$basePath}/{$year}/{$month}";
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'mp3';
        $storedFilename = Str::uuid().'.'.$extension;

        $stream = fopen($absolutePath, 'r');

        if ($stream === false) {
            throw new \RuntimeException("Could not open file for reading: {$absolutePath}");
        }

        try {
            Storage::disk($disk)->put("{$directory}/{$storedFilename}", $stream);
        } finally {
            fclose($stream);
        }

        return "{$directory}/{$storedFilename}";
    }

    /**
     * @param  array<string, mixed>|null  $csvRow
     * @return array<string, mixed>
     */
    private function buildProcessingMetadata(?array $csvRow): array
    {
        $id3Metadata = $this->buildId3Metadata($csvRow);

        if ($id3Metadata === null) {
            return [];
        }

        return ['id3_metadata' => $id3Metadata->toArray()];
    }

    /**
     * @param  array<string, mixed>|null  $csvRow
     */
    private function buildId3Metadata(?array $csvRow): ?ProcessingId3Metadata
    {
        if ($csvRow === null) {
            return null;
        }

        $title = $this->stringOrNull($csvRow['Title'] ?? null);
        $preacher = $this->stringOrNull($csvRow['Preacher'] ?? null);
        $series = $this->stringOrNull($csvRow['Series'] ?? null);

        $book = $this->stringOrNull($csvRow['Book'] ?? null);
        $reference = $this->stringOrNull($csvRow['Reference'] ?? null);
        $fullReference = match (true) {
            $book !== null && $reference !== null => "{$book} {$reference}",
            $book !== null => $book,
            $reference !== null => $reference,
            default => null,
        };

        if ($title === null && $preacher === null && $series === null && $fullReference === null) {
            return null;
        }

        return new ProcessingId3Metadata(
            title: $title,
            preacher: $preacher,
            series: $series,
            reference: $fullReference,
        );
    }

    /**
     * @param  array<string, mixed>|null  $csvRow
     */
    private function extractDate(?array $csvRow): ?Carbon
    {
        $raw = $this->stringOrNull($csvRow['Date'] ?? null);

        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $csvRow
     */
    private function extractService(?array $csvRow): ?SermonService
    {
        $raw = $this->stringOrNull($csvRow['AM/PM'] ?? null);

        if ($raw === null) {
            return null;
        }

        return match (strtolower($raw)) {
            'am', 'morning' => SermonService::Morning,
            'pm', 'evening' => SermonService::Evening,
            default => SermonService::Other,
        };
    }

    /**
     * @param  array<string, mixed>|null  $csvRow
     */
    private function extractDuration(?array $csvRow): ?float
    {
        $raw = $this->stringOrNull($csvRow['Duration'] ?? null);

        if ($raw === null) {
            return null;
        }

        // Support HH:MM:SS or MM:SS formats
        $parts = explode(':', $raw);

        if (count($parts) === 3) {
            return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (float) $parts[2];
        }

        if (count($parts) === 2) {
            return ((int) $parts[0] * 60) + (float) $parts[1];
        }

        return is_numeric($raw) ? (float) $raw : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
