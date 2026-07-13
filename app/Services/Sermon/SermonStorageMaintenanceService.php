<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Models\Sermon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * @phpstan-type MigrationSummary array{examined:int,migrated:int,skipped:int,missing:int,failed:int}
 * @phpstan-type MigrationItem array{status:string,label:string}
 * @phpstan-type VerificationSummary array{accessible:int,missing:int,total_size:int}
 */
class SermonStorageMaintenanceService
{
    private const REMOTE_VERIFICATION_ATTEMPTS = 5;

    private const REMOTE_VERIFICATION_DELAY_MS = 1000;

    public function __construct(
        private readonly SermonStorageService $sermonStorageService,
    ) {}

    /**
     * @param  list<string>  $patterns
     * @param  ?callable(MigrationItem): void  $progress
     * @return array{
     *     dry_run: bool,
     *     summary: MigrationSummary,
     *     items: list<MigrationItem>
     * }
     */
    public function migrateStorage(
        string $targetDisk,
        array $patterns,
        bool $dryRun,
        int $batchSize,
        int $delayMs = 0,
        ?callable $progress = null,
    ): array {
        $summary = $this->blankMigrationSummary();
        $items = [];

        foreach ($patterns as $pattern) {
            $query = match ($pattern) {
                'legacy' => Sermon::query()
                    ->whereNotNull('audio_file_path')
                    ->where('audio_file_path', '!=', '')
                    ->whereRaw('audio_file_path NOT LIKE "%/%"'),
                'storage' => Sermon::query()
                    ->whereNotNull('audio_file_path')
                    ->where('audio_file_path', '!=', '')
                    ->whereRaw('audio_file_path LIKE "%/%"'),
                'processing' => Sermon::query()
                    ->where(function ($query): void {
                        $query->whereNotNull('transcript_file_path')
                            ->orWhereNotNull('video_file_path');
                    }),
                default => Sermon::query()->whereRaw('1 = 0'),
            };

            foreach ($query->lazyById($batchSize) as $sermon) {
                $summary['examined']++;
                $migration = $this->migrateSermonRecord($sermon, $targetDisk, $dryRun);
                $summary[$migration['summary_key']]++;
                $item = [
                    'status' => $migration['status'],
                    'label' => "[{$pattern}] {$migration['label']}",
                ];
                $items[] = $item;
                if ($progress !== null) {
                    $progress($item);
                }

                if (
                    ! $dryRun
                    && $delayMs > 0
                    && $migration['summary_key'] === 'migrated'
                    && $this->isEventuallyConsistentDisk($targetDisk)
                ) {
                    usleep($delayMs * 1000);
                }
            }
        }

        return [
            'dry_run' => $dryRun,
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * @param  ?callable(MigrationItem): void  $progress
     * @return array{
     *     dry_run: bool,
     *     summary: MigrationSummary,
     *     items: list<MigrationItem>
     * }
     */
    public function migrateLivestreamAudio(bool $dryRun, bool $cleanup, ?callable $progress = null): array
    {
        $summary = $this->blankMigrationSummary();
        $items = [];

        $sermons = Sermon::query()
            ->whereNotNull('audio_file_path')
            ->where('audio_file_path', 'LIKE', 'sermons/____/__/%.mp3')
            ->orderByDesc('created_at')
            ->lazy();

        foreach ($sermons as $sermon) {
            $summary['examined']++;

            $filename = (string) $sermon->audio_file_path;

            if (Storage::disk('public')->exists($filename)) {
                $summary['skipped']++;
                $item = [
                    'status' => 'skip',
                    'label' => "{$filename} already exists on public storage",
                ];
                $items[] = $item;
                if ($progress !== null) {
                    $progress($item);
                }

                continue;
            }

            if (! Storage::disk('local')->exists($filename)) {
                $summary['missing']++;
                $item = [
                    'status' => 'missing',
                    'label' => "{$filename} missing from local storage",
                ];
                $items[] = $item;
                if ($progress !== null) {
                    $progress($item);
                }

                continue;
            }

            if ($dryRun) {
                $summary['migrated']++;
                $item = [
                    'status' => 'dry-run',
                    'label' => "Would migrate {$filename} from local to public",
                ];
                $items[] = $item;
                if ($progress !== null) {
                    $progress($item);
                }

                continue;
            }

            try {
                $this->copyFile('local', $filename, 'public', $filename);

                if ($cleanup) {
                    Storage::disk('local')->delete($filename);
                }

                $summary['migrated']++;
                $item = [
                    'status' => 'ok',
                    'label' => "Migrated {$filename}",
                ];
                $items[] = $item;
                if ($progress !== null) {
                    $progress($item);
                }
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $item = [
                    'status' => 'error',
                    'label' => "{$filename} failed: {$exception->getMessage()}",
                ];
                $items[] = $item;
                if ($progress !== null) {
                    $progress($item);
                }
            }
        }

        return [
            'dry_run' => $dryRun,
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * @param  ?callable(MigrationItem): void  $progress
     * @return array{
     *     dry_run: bool,
     *     summary: MigrationSummary,
     *     items: list<MigrationItem>
     * }
     */
    public function migrateLocalFiles(
        bool $dryRun,
        string $sourceDisk = 'public',
        string $targetDisk = 'do_spaces',
        bool $force = false,
        ?string $pathPrefix = null,
        ?string $startAfter = null,
        ?callable $progress = null,
    ): array {
        $pathPrefix = $this->normalizePathPrefix($pathPrefix) ?? 'sermons';

        return $this->migrateFiles(
            files: $this->filterPaths(
                Storage::disk($sourceDisk)->allFiles($pathPrefix),
                $startAfter,
            ),
            dryRun: $dryRun,
            sourceDisk: $sourceDisk,
            targetDisk: $targetDisk,
            force: $force,
            progress: $progress,
        );
    }

    /**
     * @param  ?callable(MigrationItem): void  $progress
     * @return array{
     *     dry_run: bool,
     *     summary: MigrationSummary,
     *     items: list<MigrationItem>
     * }
     */
    public function migrateReferencedSermonAudio(
        bool $dryRun,
        string $sourceDisk = 'public',
        string $targetDisk = 'do_spaces',
        bool $force = false,
        ?string $pathPrefix = null,
        ?string $startAfter = null,
        ?callable $progress = null,
    ): array {
        return $this->migrateFiles(
            files: $this->referencedSermonAudioPaths($pathPrefix, $startAfter),
            dryRun: $dryRun,
            sourceDisk: $sourceDisk,
            targetDisk: $targetDisk,
            force: $force,
            progress: $progress,
        );
    }

    /**
     * @param  ?callable(MigrationItem): void  $progress
     * @return array{
     *     summary: VerificationSummary,
     *     missing: list<array{id:int,title:string,filename:string,expected_path:string,pattern:string}>,
     *     storage_stats: array<string,mixed>
     * }
     */
    public function verifyStorage(string $disk, ?callable $progress = null): array
    {
        $accessible = 0;
        $totalSize = 0;
        $missing = [];

        Sermon::query()
            ->whereNotNull('audio_file_path')
            ->where('audio_file_path', '!=', '')
            ->select(['id', 'title', 'audio_file_path', 'filetype'])
            ->chunk(100, function ($sermons) use ($disk, &$accessible, &$totalSize, &$missing, $progress): void {
                foreach ($sermons as $sermon) {
                    $fileInfo = $this->sermonStorageService->getSermonFileInfo($sermon);
                    $verificationDisk = $fileInfo['type'] === 'private' ? $fileInfo['disk'] : $disk;

                    if (Storage::disk($verificationDisk)->exists($fileInfo['path'])) {
                        $accessible++;
                        $totalSize += (int) Storage::disk($verificationDisk)->size($fileInfo['path']);
                        if ($progress !== null) {
                            $progress([
                                'status' => 'ok',
                                'label' => "#{$sermon->id} {$fileInfo['path']} verified on {$verificationDisk}",
                            ]);
                        }

                        continue;
                    }

                    $missing[] = [
                        'id' => $sermon->id,
                        'title' => $sermon->title,
                        'filename' => (string) $sermon->audio_file_path,
                        'expected_path' => $fileInfo['path'],
                        'pattern' => $fileInfo['type'],
                    ];
                    if ($progress !== null) {
                        $progress([
                            'status' => 'missing',
                            'label' => "#{$sermon->id} {$fileInfo['path']} missing on {$verificationDisk}",
                        ]);
                    }
                }
            });

        return [
            'summary' => [
                'accessible' => $accessible,
                'missing' => count($missing),
                'total_size' => $totalSize,
            ],
            'missing' => $missing,
            'storage_stats' => $this->sermonStorageService->getStorageStats(),
        ];
    }

    /**
     * @return array{status:string,summary_key:'migrated'|'skipped'|'missing'|'failed',label:string}
     */
    private function migrateSermonRecord(Sermon $sermon, string $targetDisk, bool $dryRun): array
    {
        $fileInfo = $this->sermonStorageService->getSermonFileInfo($sermon);
        $isLegacy = $fileInfo['type'] === 'legacy';
        $sourceDisk = $isLegacy
            ? 'public_images'
            : $fileInfo['disk'];
        $sourcePath = $isLegacy
            ? $fileInfo['original_path']
            : $fileInfo['path'];
        $targetPath = $fileInfo['path'];

        if (Storage::disk($targetDisk)->exists($targetPath)) {
            if ($isLegacy && $dryRun) {
                return [
                    'status' => 'dry-run',
                    'summary_key' => 'migrated',
                    'label' => "#{$sermon->id} would canonicalise {$sermon->audio_file_path} -> {$targetPath}",
                ];
            }

            if ($isLegacy) {
                try {
                    $this->canonicaliseLegacyPath($sermon, $targetPath);
                } catch (\Throwable $exception) {
                    return [
                        'status' => 'error',
                        'summary_key' => 'failed',
                        'label' => "#{$sermon->id} failed: {$exception->getMessage()}",
                    ];
                }

                return [
                    'status' => 'ok',
                    'summary_key' => 'migrated',
                    'label' => "#{$sermon->id} canonicalised {$targetPath}",
                ];
            }

            return [
                'status' => 'skip',
                'summary_key' => 'skipped',
                'label' => "#{$sermon->id} {$targetPath} already exists on {$targetDisk}",
            ];
        }

        if (! Storage::disk($sourceDisk)->exists($sourcePath)) {
            return [
                'status' => 'missing',
                'summary_key' => 'missing',
                'label' => "#{$sermon->id} {$sourcePath} missing on {$sourceDisk}",
            ];
        }

        if ($dryRun) {
            return [
                'status' => 'dry-run',
                'summary_key' => 'migrated',
                'label' => "#{$sermon->id} would migrate {$sourcePath} -> {$targetPath}",
            ];
        }

        try {
            $this->copyFile($sourceDisk, $sourcePath, $targetDisk, $targetPath);

            if ($isLegacy) {
                $this->canonicaliseLegacyPath($sermon, $targetPath);
            }

            return [
                'status' => 'ok',
                'summary_key' => 'migrated',
                'label' => "#{$sermon->id} migrated {$targetPath}",
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'summary_key' => 'failed',
                'label' => "#{$sermon->id} failed: {$exception->getMessage()}",
            ];
        }
    }

    private function canonicaliseLegacyPath(Sermon $sermon, string $targetPath): void
    {
        $originalPath = $sermon->audio_file_path;

        $updated = Sermon::query()
            ->whereKey($sermon->getKey())
            ->where('audio_file_path', $originalPath)
            ->update(['audio_file_path' => $targetPath]);

        if ($updated !== 1) {
            throw new RuntimeException("Sermon #{$sermon->id} changed while its storage path was being canonicalised.");
        }
    }

    /**
     * @param  list<string>  $patterns
     */
    public function countStorageCandidates(array $patterns): int
    {
        $count = 0;

        foreach ($patterns as $pattern) {
            $count += match ($pattern) {
                'legacy' => Sermon::query()
                    ->whereNotNull('audio_file_path')
                    ->where('audio_file_path', '!=', '')
                    ->whereRaw('audio_file_path NOT LIKE "%/%"')
                    ->count(),
                'storage' => Sermon::query()
                    ->whereNotNull('audio_file_path')
                    ->where('audio_file_path', '!=', '')
                    ->whereRaw('audio_file_path LIKE "%/%"')
                    ->count(),
                'processing' => Sermon::query()
                    ->where(function ($query): void {
                        $query->whereNotNull('transcript_file_path')
                            ->orWhereNotNull('video_file_path');
                    })
                    ->count(),
                default => 0,
            };
        }

        return $count;
    }

    public function countLivestreamAudioCandidates(): int
    {
        return Sermon::query()
            ->whereNotNull('audio_file_path')
            ->where('audio_file_path', 'LIKE', 'sermons/____/__/%.mp3')
            ->count();
    }

    public function countLocalFiles(string $sourceDisk = 'public', ?string $pathPrefix = null, ?string $startAfter = null): int
    {
        $pathPrefix = $this->normalizePathPrefix($pathPrefix) ?? 'sermons';

        return count($this->filterPaths(Storage::disk($sourceDisk)->allFiles($pathPrefix), $startAfter));
    }

    public function countReferencedSermonAudioCandidates(?string $pathPrefix = null, ?string $startAfter = null): int
    {
        return count($this->referencedSermonAudioPaths($pathPrefix, $startAfter));
    }

    public function countVerifiableSermons(): int
    {
        return Sermon::query()
            ->whereNotNull('audio_file_path')
            ->where('audio_file_path', '!=', '')
            ->count();
    }

    private function copyFile(string $sourceDisk, string $sourcePath, string $targetDisk, string $targetPath): void
    {
        $content = Storage::disk($sourceDisk)->get($sourcePath);

        if (! is_string($content)) {
            throw new RuntimeException("Unable to read {$sourcePath} from {$sourceDisk}");
        }

        $written = Storage::disk($targetDisk)->put($targetPath, $content);

        if ($written !== true) {
            throw new RuntimeException("Unable to write {$targetPath} to {$targetDisk}");
        }

        $this->verifyCopiedFile($sourceDisk, $sourcePath, $targetDisk, $targetPath);
    }

    /**
     * @return MigrationSummary
     */
    private function blankMigrationSummary(): array
    {
        return [
            'examined' => 0,
            'migrated' => 0,
            'skipped' => 0,
            'missing' => 0,
            'failed' => 0,
        ];
    }

    private function verifyCopiedFile(string $sourceDisk, string $sourcePath, string $targetDisk, string $targetPath): void
    {
        $sourceSize = (int) Storage::disk($sourceDisk)->size($sourcePath);
        $attempts = $this->isEventuallyConsistentDisk($targetDisk) ? self::REMOTE_VERIFICATION_ATTEMPTS : 1;
        $delayMs = $this->isEventuallyConsistentDisk($targetDisk) ? self::REMOTE_VERIFICATION_DELAY_MS : 0;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $exists = Storage::disk($targetDisk)->exists($targetPath);
            $targetSize = $exists ? (int) Storage::disk($targetDisk)->size($targetPath) : null;

            if ($exists && $targetSize === $sourceSize) {
                return;
            }

            if ($attempt < $attempts && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        throw new RuntimeException("Verification failed for {$targetPath}");
    }

    private function isEventuallyConsistentDisk(string $disk): bool
    {
        $driver = config("filesystems.disks.{$disk}.driver");

        return $driver === 's3';
    }

    /**
     * @param  iterable<string>  $files
     * @param  ?callable(MigrationItem): void  $progress
     * @return array{
     *     dry_run: bool,
     *     summary: MigrationSummary,
     *     items: list<MigrationItem>
     * }
     */
    private function migrateFiles(
        iterable $files,
        bool $dryRun,
        string $sourceDisk,
        string $targetDisk,
        bool $force,
        ?callable $progress = null,
    ): array {
        $summary = $this->blankMigrationSummary();
        $items = [];

        foreach ($files as $file) {
            $summary['examined']++;

            if (! $force) {
                try {
                    $targetExists = $this->targetFileExists($targetDisk, $file);
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $item = [
                        'status' => 'error',
                        'label' => "{$file} failed during target existence check: {$exception->getMessage()}",
                    ];
                    $items[] = $item;
                    if ($progress !== null) {
                        $progress($item);
                    }

                    continue;
                }

                if ($targetExists) {
                    $summary['skipped']++;
                    $item = [
                        'status' => 'skip',
                        'label' => "{$file} already exists on {$targetDisk}",
                    ];
                    $items[] = $item;
                    if ($progress !== null) {
                        $progress($item);
                    }

                    continue;
                }
            }

            if ($dryRun) {
                $summary['migrated']++;
                $item = [
                    'status' => 'dry-run',
                    'label' => $force
                        ? "Would migrate {$file} to {$targetDisk} (force overwrite enabled)"
                        : "Would migrate {$file} to {$targetDisk}",
                ];
                $items[] = $item;
                if ($progress !== null) {
                    $progress($item);
                }

                continue;
            }

            try {
                $this->copyFile($sourceDisk, $file, $targetDisk, $file);

                $summary['migrated']++;
                $item = [
                    'status' => 'ok',
                    'label' => $force
                        ? "Migrated {$file} with overwrite"
                        : "Migrated {$file}",
                ];
                $items[] = $item;
                if ($progress !== null) {
                    $progress($item);
                }
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $item = [
                    'status' => 'error',
                    'label' => "{$file} failed: {$exception->getMessage()}",
                ];
                $items[] = $item;
                if ($progress !== null) {
                    $progress($item);
                }
            }
        }

        return [
            'dry_run' => $dryRun,
            'summary' => $summary,
            'items' => $items,
        ];
    }

    /**
     * @return list<string>
     */
    private function referencedSermonAudioPaths(?string $pathPrefix = null, ?string $startAfter = null): array
    {
        $pathPrefix = $this->normalizePathPrefix($pathPrefix);
        $startAfter = $this->normalizePathPrefix($startAfter);

        /** @var list<string> $paths */
        $paths = Sermon::query()
            ->whereNotNull('audio_file_path')
            ->where('audio_file_path', '!=', '')
            ->when(
                $pathPrefix !== null,
                fn ($query) => $query->where('audio_file_path', 'like', "{$pathPrefix}%")
            )
            ->when(
                $startAfter !== null,
                fn ($query) => $query->where('audio_file_path', '>', $startAfter)
            )
            ->orderBy('audio_file_path')
            ->pluck('audio_file_path')
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '' && str_contains($path, '/'))
            ->unique()
            ->values()
            ->all();

        return $paths;
    }

    private function normalizePathPrefix(?string $pathPrefix): ?string
    {
        if (! is_string($pathPrefix) || trim($pathPrefix) === '') {
            return null;
        }

        return trim(trim($pathPrefix), '/');
    }

    protected function targetFileExists(string $targetDisk, string $path): bool
    {
        return Storage::disk($targetDisk)->exists($path);
    }

    /**
     * @param  array<int, string>  $paths
     * @return list<string>
     */
    private function filterPaths(array $paths, ?string $startAfter = null): array
    {
        $startAfter = $this->normalizePathPrefix($startAfter);

        sort($paths, SORT_STRING);

        if ($startAfter === null) {
            return $paths;
        }

        return array_values(array_filter(
            $paths,
            fn (string $path): bool => $path > $startAfter,
        ));
    }
}
