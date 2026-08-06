<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

/**
 * The source-kind-agnostic half of a §7.3 curation manifest.
 *
 * §7.3 requires "one strict manifest format across Email, OpenLP and livestream
 * acquisition". The parts that are genuinely identical across those three —
 * envelope validation, path safety, hashing, inventory and curation authority —
 * live here so a third source does not become a third near-copy.
 *
 * What deliberately stays with each source: its format string, version, source
 * kind, expected counts, parse/concatenation vocabulary, and above all its
 * *logical identity invariant*. OpenLP forbids two included archives resolving
 * to the same date and service; Email must permit it, because a corrected
 * order of service and a partial hymn list are both legitimate assertions about
 * one service. Sharing that rule would be wrong, so it is not shared.
 */
class CurationManifestReader
{
    public const Dispositions = ['include', 'duplicate-of', 'exclude'];

    /**
     * Read, decode and envelope-check a manifest file.
     *
     * @return array{batch_key:string, entries:list<mixed>}
     */
    public function envelope(string $manifestPath, string $format, int $version, string $label): array
    {
        $manifestRealPath = realpath($manifestPath);

        if (! is_string($manifestRealPath) || ! is_file($manifestRealPath)) {
            throw new RuntimeException("{$label} curation manifest does not exist: {$manifestPath}");
        }

        $manifestBytes = file_get_contents($manifestRealPath);

        if (! is_string($manifestBytes)) {
            throw new RuntimeException("Unable to read the {$label} curation manifest.");
        }

        try {
            $manifest = json_decode($manifestBytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The {$label} curation manifest is not valid JSON.", previous: $exception);
        }

        if (! is_array($manifest)) {
            throw new RuntimeException("The {$label} curation manifest must be a JSON object.");
        }

        if (($manifest['format'] ?? null) !== $format || ($manifest['version'] ?? null) !== $version) {
            throw new RuntimeException("Unsupported {$label} curation manifest format or version.");
        }

        $batchKey = $manifest['batch_key'] ?? null;

        if (! is_string($batchKey) || trim($batchKey) === '') {
            throw new RuntimeException("The {$label} curation manifest requires a non-empty batch_key.");
        }

        $entries = $manifest['entries'] ?? null;

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RuntimeException("The {$label} curation manifest entries must be a JSON list.");
        }

        return ['batch_key' => trim($batchKey), 'entries' => $entries];
    }

    public function requireDirectory(string $directory, string $label): string
    {
        $root = realpath($directory);

        if (! is_string($root) || ! is_dir($root)) {
            throw new RuntimeException("Raw {$label} directory does not exist: {$directory}");
        }

        return $root;
    }

    /**
     * Hash every file below the root, keyed by root-relative path.
     *
     * @return array<string, string>
     */
    public function inventory(string $rawRoot, string $label): array
    {
        $inventory = [];

        foreach (File::allFiles($rawRoot) as $file) {
            $realPath = $file->getRealPath();

            if (! is_string($realPath) || ! $this->isWithinRoot($realPath, $rawRoot)) {
                throw new RuntimeException("Raw {$label} inventory escapes its root: {$file->getPathname()}");
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($rawRoot) + 1));
            $hash = hash_file('sha256', $realPath);

            if (! is_string($hash)) {
                throw new RuntimeException("Unable to hash raw {$label} file: {$relativePath}");
            }

            $inventory[$relativePath] = $hash;
        }

        ksort($inventory, SORT_STRING);

        return $inventory;
    }

    /**
     * §3.3: reject absolute, traversal, symlink and out-of-root paths.
     */
    public function validateRelativePath(string $relativePath, string $rawRoot): void
    {
        if (
            $relativePath === ''
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '\\')
            || in_array('..', explode('/', $relativePath), true)
            || in_array('', explode('/', $relativePath), true)
        ) {
            throw new RuntimeException("Unsafe manifest path: {$relativePath}");
        }

        $resolved = realpath("{$rawRoot}/{$relativePath}");

        if (is_string($resolved) && ! $this->isWithinRoot($resolved, $rawRoot)) {
            throw new RuntimeException("Manifest path escapes the raw directory: {$relativePath}");
        }
    }

    /**
     * Resolve an approved entry's path, proving it is a real file inside the
     * root whose bytes still match the approved size and hash.
     */
    public function verifiedPath(string $rawRoot, string $relativePath, string $sha256, int $byteSize, string $label): string
    {
        $this->validateRelativePath($relativePath, $rawRoot);
        $path = "{$rawRoot}/{$relativePath}";

        if (! is_file($path) || $this->containsSymlink($rawRoot, $relativePath)) {
            throw new RuntimeException("Approved {$label} source is missing or symlinked: {$relativePath}");
        }

        $realPath = realpath($path);

        if (! is_string($realPath) || ! $this->isWithinRoot($realPath, $rawRoot)) {
            throw new RuntimeException("Approved {$label} source escapes the raw directory: {$relativePath}");
        }

        if ($this->fileSize($realPath) !== $byteSize) {
            throw new RuntimeException("Byte-size mismatch for {$relativePath}.");
        }

        $hash = hash_file('sha256', $realPath);

        if (! is_string($hash) || ! hash_equals($sha256, $hash)) {
            throw new RuntimeException("SHA-256 mismatch for {$relativePath}.");
        }

        return $realPath;
    }

    public function containsSymlink(string $rawRoot, string $relativePath): bool
    {
        $path = $rawRoot;

        foreach (explode('/', $relativePath) as $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;

            if (is_link($path)) {
                return true;
            }
        }

        return false;
    }

    public function fileSize(string $path): int
    {
        $size = filesize($path);

        if (! is_int($size)) {
            throw new RuntimeException("Unable to determine file size: {$path}");
        }

        return $size;
    }

    public function isWithinRoot(string $path, string $root): bool
    {
        return $path !== $root && str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * §7.3 requires every entry to record who decided it and when, or the
     * approved rule version that decided it. Without one of those the manifest
     * is a cache of a filename heuristic rather than mutation authority.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0:?string,1:?string,2:?string}
     */
    public function curationAuthority(array $entry, string $relativePath, string $label): array
    {
        $decidedBy = $this->nullableString($entry, 'decided_by', $label);
        $decidedAt = $this->nullableString($entry, 'decided_at', $label);
        $decisionRuleVersion = $this->nullableString($entry, 'decision_rule_version', $label);

        if ($decidedBy === null && $decidedAt === null && $decisionRuleVersion === null) {
            throw new RuntimeException(
                "Entry {$relativePath} declares no curation authority: it requires decided_by with decided_at, ".
                'or an approved decision_rule_version.'
            );
        }

        if (($decidedBy === null) !== ($decidedAt === null)) {
            throw new RuntimeException(
                "Entry {$relativePath} must declare decided_by and decided_at together."
            );
        }

        if ($decidedAt !== null && $this->isoTimestamp($decidedAt) === null) {
            throw new RuntimeException(
                "Entry {$relativePath} has an invalid decided_at; an ISO-8601 timestamp is required."
            );
        }

        return [$decidedBy, $decidedAt, $decisionRuleVersion];
    }

    public function isoTimestamp(string $value): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);

        if ($parsed === false || $parsed->format(DateTimeInterface::ATOM) !== $value) {
            return null;
        }

        return $value;
    }

    /**
     * A `duplicate-of` entry must be byte-identical to an entry that is itself
     * included, so nothing is dropped by calling it a duplicate.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    public function validateDuplicateHashes(array $entries): void
    {
        $includedHashes = collect($entries)
            ->where('disposition', 'include')
            ->pluck('sha256');
        $duplicateIncludedHashes = $includedHashes->duplicates()->unique()->values();

        if ($duplicateIncludedHashes->isNotEmpty()) {
            throw new RuntimeException('Included entries contain duplicate SHA-256 hashes.');
        }

        $includedHashLookup = $includedHashes->flip();

        foreach ($entries as $entry) {
            if ($entry['disposition'] !== 'duplicate-of') {
                continue;
            }

            if (
                ! is_string($entry['duplicate_target_hash'])
                || ! hash_equals($entry['sha256'], $entry['duplicate_target_hash'])
                || ! $includedHashLookup->has($entry['duplicate_target_hash'])
            ) {
                throw new RuntimeException("Duplicate entry {$entry['relative_path']} does not identify an included byte-identical target.");
            }
        }
    }

    /** @param array<string, mixed> $entry */
    public function requiredString(array $entry, string $key, int $offset, string $label): string
    {
        $value = $entry[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$label} curation entry {$offset} requires {$key}.");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $entry */
    public function nullableString(array $entry, string $key, string $label): ?string
    {
        $value = $entry[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$label} curation field {$key} must be a non-empty string or null.");
        }

        return trim($value);
    }
}
