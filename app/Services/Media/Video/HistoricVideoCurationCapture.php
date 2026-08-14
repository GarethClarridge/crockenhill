<?php

declare(strict_types=1);

namespace App\Services\Media\Video;

use JsonException;
use RuntimeException;

/**
 * Stage two of video curation: turn the operator's adjudicated worksheet into
 * the hash-covered manifest the importer will treat as mutation authority.
 *
 * This is the only step that reads file contents, and it is meant to be run
 * once, at freeze. Everything before it works from paths, sizes and container
 * headers so that adjudication stays cheap to iterate.
 *
 * The transform is deliberately mechanical — hash each declared file, drop the
 * worksheet-only duration, keep every decision exactly as the operator left it.
 * Curation judgement belongs in the worksheet; if this class started making
 * decisions, the artifact the operator approved would stop being the artifact
 * that governs.
 */
class HistoricVideoCurationCapture
{
    private const MANIFEST_FORMAT = 'crockenhill-historic-video-curation';

    private const MANIFEST_VERSION = 4;

    /**
     * @return array{
     *     format:string,
     *     version:int,
     *     batch_key:string,
     *     entries:list<array<string, mixed>>
     * }
     */
    public function capture(
        string $rawDirectory,
        string $worksheetPath,
        ?callable $onEntryCaptured = null,
    ): array {
        $rawRoot = realpath($rawDirectory);

        if (! is_string($rawRoot) || ! is_dir($rawRoot)) {
            throw new RuntimeException("Historic video directory does not exist: {$rawDirectory}");
        }

        $worksheet = $this->worksheet($worksheetPath);
        $entries = [];

        foreach ($worksheet['entries'] as $offset => $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException("Historic video worksheet entry {$offset} must be an object.");
            }

            $entries[] = $this->captureEntry($rawRoot, $entry, $offset);

            if ($onEntryCaptured !== null) {
                $onEntryCaptured(count($entries), count($worksheet['entries']));
            }
        }

        return [
            'format' => self::MANIFEST_FORMAT,
            'version' => self::MANIFEST_VERSION,
            'batch_key' => $worksheet['batch_key'],
            'entries' => $entries,
        ];
    }

    /**
     * @return array{batch_key:string, entries:list<mixed>}
     */
    private function worksheet(string $path): array
    {
        $bytes = file_get_contents($path);

        if (! is_string($bytes)) {
            throw new RuntimeException("Historic video curation worksheet does not exist: {$path}");
        }

        try {
            $worksheet = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Historic video curation worksheet is not valid JSON.', previous: $exception);
        }

        if (! is_array($worksheet)
            || ($worksheet['format'] ?? null) !== HistoricVideoCurationDraft::WORKSHEET_FORMAT
            || ($worksheet['version'] ?? null) !== HistoricVideoCurationDraft::WORKSHEET_VERSION) {
            throw new RuntimeException('Unsupported historic video curation worksheet format or version.');
        }

        $batchKey = $worksheet['batch_key'] ?? null;
        $entries = $worksheet['entries'] ?? null;

        if (! is_string($batchKey) || trim($batchKey) === '') {
            throw new RuntimeException('Historic video curation worksheet requires a batch key.');
        }

        if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
            throw new RuntimeException('Historic video curation worksheet entries must be a non-empty JSON list.');
        }

        return ['batch_key' => trim($batchKey), 'entries' => $entries];
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return array<string, mixed>
     */
    private function captureEntry(string $rawRoot, array $entry, int $offset): array
    {
        $files = $entry['files'] ?? null;

        if (! is_array($files) || ! array_is_list($files) || $files === []) {
            throw new RuntimeException("Historic video worksheet entry {$offset} requires source files.");
        }

        $captured = [];

        foreach ($files as $file) {
            if (! is_array($file)) {
                throw new RuntimeException("Historic video worksheet entry {$offset} has an invalid source file.");
            }

            $captured[] = $this->captureFile($rawRoot, $file, $offset);
        }

        $entry['files'] = $captured;

        // The worksheet carries duration purely so the operator could grade
        // corroboration; the manifest keeps the grade, not the evidence for it.
        unset($entry['duration_minutes']);

        return $entry;
    }

    /**
     * @param  array<array-key, mixed>  $file
     * @return array{relative_path:string, sha256:string, byte_size:int}
     */
    private function captureFile(string $rawRoot, array $file, int $offset): array
    {
        $relativePath = $file['relative_path'] ?? null;

        if (! is_string($relativePath) || trim($relativePath) === '') {
            throw new RuntimeException("Historic video worksheet entry {$offset} has a source file without a path.");
        }

        $absolute = $rawRoot.'/'.$relativePath;

        if (! is_file($absolute)) {
            throw new RuntimeException("Historic video source is missing since drafting: {$relativePath}");
        }

        $size = filesize($absolute);
        $hash = hash_file('sha256', $absolute);

        if (! is_int($size) || $size < 1 || ! is_string($hash)) {
            throw new RuntimeException("Historic video source could not be read: {$relativePath}");
        }

        // The worksheet's size was taken before adjudication. If it no longer
        // matches, the corpus moved under the operator and the decisions they
        // made were about a different file.
        $drafted = $file['byte_size'] ?? null;

        if (is_int($drafted) && $drafted !== $size) {
            throw new RuntimeException(
                "Historic video source changed size since drafting: {$relativePath} was {$drafted}, now {$size}.",
            );
        }

        return ['relative_path' => $relativePath, 'sha256' => $hash, 'byte_size' => $size];
    }
}
