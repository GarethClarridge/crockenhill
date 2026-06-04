<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Data\ProcessingId3Metadata;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MetadataExtractionService;
use App\Services\Processing\ProcessingRunOrchestrator;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class LegacySermonImporter
{
    use SanitizesLogData;

    public function __construct(
        private readonly ProcessingRunOrchestrator $orchestrator,
        private readonly MetadataExtractionService $metadataExtractionService,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $csvIndex
     * @param  \Closure(string $filename, string $result, list<array{field: string, csv: string, embedded: string}> $conflicts, string|null $message): void|null  $onProgress
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
            $conflicts = [];
            $message = null;

            try {
                $fileResult = $this->importFile(
                    absolutePath: $absolutePath,
                    filename: $filename,
                    csvIndex: $csvIndex,
                    dryRun: $dryRun,
                    force: $force,
                );

                $result = $fileResult['result'];
                $conflicts = $fileResult['conflicts'];

                if ($result === 'skipped') {
                    $skipped++;
                } else {
                    $imported++;
                }
            } catch (\Throwable $e) {
                Log::error('Legacy sermon import failed for file', $this->sanitizeArrayForLog([
                    'file' => $filename,
                    'error' => $e->getMessage(),
                ]));
                $result = 'error';
                $message = $e->getMessage();
                $errors++;
            }

            if ($onProgress !== null) {
                $onProgress($filename, $result, $conflicts, $message);
            }

            if (! $dryRun && $delay > 0) {
                sleep($delay);
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param  array<string, array<string, mixed>>  $csvIndex
     * @return array{result: string, conflicts: list<array{field: string, csv: string, embedded: string}>}
     */
    private function importFile(
        string $absolutePath,
        string $filename,
        array $csvIndex,
        bool $dryRun,
        bool $force,
    ): array {
        $fileHash = hash_file('sha256', $absolutePath);
        $fileSize = filesize($absolutePath);

        if ($fileHash === false || $fileSize === false) {
            throw new \RuntimeException("Could not read file: {$absolutePath}");
        }

        if (! $force) {
            if ($this->isDuplicateByHash($fileHash)) {
                return ['result' => 'skipped', 'conflicts' => []];
            }

            if ($this->isDuplicateByFilename($filename)) {
                return ['result' => 'skipped', 'conflicts' => []];
            }
        }

        $csvRow = $csvIndex[$this->normaliseTapeId($filename)] ?? null;

        $date = $this->extractDate($csvRow);

        if ($date === null) {
            throw new \RuntimeException("No valid historic date found for {$filename}. Check the Tape Index CSV has a matching Tape ID and Date.");
        }

        $embeddedMetadata = $this->metadataExtractionService->extractId3MetadataFromPath($absolutePath);
        $conflicts = $this->detectMetadataConflicts($csvRow, $embeddedMetadata);
        $result = $conflicts === [] ? 'imported' : 'conflict';

        if ($dryRun) {
            return ['result' => $result, 'conflicts' => $conflicts];
        }

        $storedPath = $this->storeFile($absolutePath, $filename, $date);

        $processingLog = MediaProcessingLog::query()->create([
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
            'processing_metadata' => $this->buildProcessingMetadata($csvRow, $embeddedMetadata, $conflicts),
        ]);

        $this->orchestrator->start($processingLog);

        return ['result' => $result, 'conflicts' => $conflicts];
    }

    /** @return list<string> */
    private function discoverMp3Files(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                continue;
            }

            if (! $file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'mp3') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
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

        return strtolower(trim((string) preg_replace('/#[^#]*#/', '', $withoutExtension)));
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
     * @param  array<string, mixed>  $embeddedMetadata
     * @param  list<array{field: string, csv: string, embedded: string}>  $conflicts
     * @return array<string, mixed>
     */
    private function buildProcessingMetadata(?array $csvRow, array $embeddedMetadata, array $conflicts): array
    {
        $metadata = [];
        $id3Metadata = $this->buildId3Metadata($csvRow, $embeddedMetadata);

        if ($id3Metadata !== null) {
            $metadata['id3_metadata'] = $id3Metadata->toArray();
        }

        $embeddedMetadata = array_filter(
            $embeddedMetadata,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );

        if ($embeddedMetadata !== []) {
            $metadata['embedded_id3_metadata'] = $embeddedMetadata;
        }

        if ($conflicts !== []) {
            $metadata['metadata_conflicts'] = $conflicts;
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>|null  $csvRow
     * @param  array<string, mixed>  $embeddedMetadata
     * @return list<array{field: string, csv: string, embedded: string}>
     */
    private function detectMetadataConflicts(?array $csvRow, array $embeddedMetadata): array
    {
        if ($csvRow === null) {
            return [];
        }

        $conflicts = [];
        $csvMetadata = $this->csvComparableMetadata($csvRow);

        foreach (['title', 'preacher', 'series', 'reference'] as $field) {
            $this->appendStringConflict($conflicts, $field, $csvMetadata[$field] ?? null, $embeddedMetadata[$field] ?? null);
        }

        $this->appendDateConflict($conflicts, $csvMetadata['date'] ?? null, $embeddedMetadata['date'] ?? null);
        $this->appendDurationConflict($conflicts, $csvMetadata['duration'] ?? null, $embeddedMetadata['duration'] ?? null);

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $csvRow
     * @return array{title: string|null, preacher: string|null, series: string|null, reference: string|null, date: string|null, duration: float|null}
     */
    private function csvComparableMetadata(array $csvRow): array
    {
        $date = $this->extractDate($csvRow);

        return [
            'title' => $this->stringOrNull($csvRow['Title'] ?? null),
            'preacher' => $this->stringOrNull($csvRow['Preacher'] ?? null),
            'series' => $this->stringOrNull($csvRow['Series'] ?? null),
            'reference' => $this->buildReference($csvRow),
            'date' => $date?->toDateString(),
            'duration' => $this->extractDuration($csvRow),
        ];
    }

    /**
     * @param  list<array{field: string, csv: string, embedded: string}>  $conflicts
     */
    private function appendStringConflict(array &$conflicts, string $field, ?string $csvValue, mixed $embeddedValue): void
    {
        $embeddedValue = $this->stringOrNull($embeddedValue);

        if ($csvValue === null || $embeddedValue === null) {
            return;
        }

        if ($this->normaliseComparableString($csvValue) === $this->normaliseComparableString($embeddedValue)) {
            return;
        }

        $conflicts[] = [
            'field' => $field,
            'csv' => $csvValue,
            'embedded' => $embeddedValue,
        ];
    }

    /**
     * @param  list<array{field: string, csv: string, embedded: string}>  $conflicts
     */
    private function appendDateConflict(array &$conflicts, ?string $csvValue, mixed $embeddedValue): void
    {
        $embeddedValue = $this->stringOrNull($embeddedValue);

        if ($csvValue === null || $embeddedValue === null) {
            return;
        }

        $normalisedEmbedded = $this->normaliseEmbeddedDate($embeddedValue);

        if ($normalisedEmbedded === null) {
            return;
        }

        if (strlen($normalisedEmbedded) === 4 && str_starts_with($csvValue, $normalisedEmbedded)) {
            return;
        }

        if ($csvValue === $normalisedEmbedded) {
            return;
        }

        $conflicts[] = [
            'field' => 'date',
            'csv' => $csvValue,
            'embedded' => $embeddedValue,
        ];
    }

    /**
     * @param  list<array{field: string, csv: string, embedded: string}>  $conflicts
     */
    private function appendDurationConflict(array &$conflicts, ?float $csvValue, mixed $embeddedValue): void
    {
        // Duration conflicts are intentionally suppressed — CSV values were hand-entered and
        // unreliable; actual duration is measured fresh from the file during processing.
    }

    private function normaliseComparableString(string $value): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($value)));
    }

    private function normaliseEmbeddedDate(string $value): ?string
    {
        if (preg_match('/^\d{4}$/', $value) === 1) {
            return $value;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve metadata using per-field source preferences:
     *   title    → CSV  (more complete/intentional)
     *   preacher → MP3  (ID3 tags more carefully maintained for this field)
     *   series   → CSV  (curated groupings)
     *   reference → CSV (MP3 tags sometimes contain dates or junk in this field)
     *
     * @param  array<string, mixed>|null  $csvRow
     * @param  array<string, mixed>  $embeddedMetadata
     */
    private function buildId3Metadata(?array $csvRow, array $embeddedMetadata): ?ProcessingId3Metadata
    {
        if ($csvRow === null) {
            return null;
        }

        $title = $this->stringOrNull($csvRow['Title'] ?? null);
        $preacher = $this->stringOrNull($embeddedMetadata['preacher'] ?? null)
            ?? $this->stringOrNull($csvRow['Preacher'] ?? null);
        $series = $this->stringOrNull($csvRow['Series'] ?? null);
        $fullReference = $this->buildReference($csvRow);

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
     * @param  array<string, mixed>  $csvRow
     */
    private function buildReference(array $csvRow): ?string
    {
        $book = $this->stringOrNull($csvRow['Book'] ?? null);
        $reference = $this->stringOrNull($csvRow['Reference'] ?? null);

        return match (true) {
            $book !== null && $reference !== null => "{$book} {$reference}",
            $book !== null => $book,
            $reference !== null => $reference,
            default => null,
        };
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
