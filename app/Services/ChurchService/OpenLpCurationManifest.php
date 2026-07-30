<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\OpenLpCurationPlan;
use App\Enums\SermonService;
use App\Services\Song\OpenLpServiceParser;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

class OpenLpCurationManifest
{
    private const ExpectedCounts = [
        'raw' => 536,
        'include' => 428,
        'duplicate-of' => 105,
        'exclude' => 3,
        'aliases' => 7,
    ];

    private const Format = 'crockenhill-openlp-curation';

    private const Version = 1;

    public function __construct(
        private readonly OpenLpServiceParser $parser,
    ) {}

    public function plan(string $rawDirectory, string $manifestPath): OpenLpCurationPlan
    {
        $rawRoot = realpath($rawDirectory);

        if (! is_string($rawRoot) || ! is_dir($rawRoot)) {
            throw new RuntimeException("Raw OpenLP directory does not exist: {$rawDirectory}");
        }

        $manifestRealPath = realpath($manifestPath);

        if (! is_string($manifestRealPath) || ! is_file($manifestRealPath)) {
            throw new RuntimeException("OpenLP curation manifest does not exist: {$manifestPath}");
        }

        $manifestBytes = file_get_contents($manifestRealPath);

        if (! is_string($manifestBytes)) {
            throw new RuntimeException('Unable to read the OpenLP curation manifest.');
        }

        try {
            $manifest = json_decode($manifestBytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The OpenLP curation manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($manifest)) {
            throw new RuntimeException('The OpenLP curation manifest must be a JSON object.');
        }

        if (($manifest['format'] ?? null) !== self::Format || ($manifest['version'] ?? null) !== self::Version) {
            throw new RuntimeException('Unsupported OpenLP curation manifest format or version.');
        }

        $entries = $manifest['entries'] ?? null;

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RuntimeException('The OpenLP curation manifest entries must be a JSON list.');
        }

        $inventory = $this->inventory($rawRoot);
        $normalizedEntries = $this->normalizeEntries($entries, $rawRoot);
        usort(
            $normalizedEntries,
            static fn (array $left, array $right): int => $left['relative_path'] <=> $right['relative_path'],
        );
        $manifestPaths = array_column($normalizedEntries, 'relative_path');
        $extraPaths = array_values(array_diff(array_keys($inventory), $manifestPaths));
        $missingPaths = array_values(array_diff($manifestPaths, array_keys($inventory)));

        if ($extraPaths !== []) {
            throw new RuntimeException('Raw OpenLP directory contains unmanifested files: '.implode(', ', $extraPaths));
        }

        if ($missingPaths !== []) {
            throw new RuntimeException('OpenLP curation manifest references missing files: '.implode(', ', $missingPaths));
        }

        foreach ($normalizedEntries as $entry) {
            if (! hash_equals($entry['sha256'], $inventory[$entry['relative_path']])) {
                throw new RuntimeException("SHA-256 mismatch for {$entry['relative_path']}.");
            }
        }

        $this->validateDuplicateHashes($normalizedEntries);
        $this->validateLogicalIdentities($normalizedEntries);
        $counts = $this->counts($normalizedEntries);

        if ($counts !== self::ExpectedCounts) {
            throw new RuntimeException(
                'OpenLP curation manifest accounting mismatch. Expected '.CanonicalJson::encode(self::ExpectedCounts).
                ', received '.CanonicalJson::encode($counts).'.'
            );
        }

        $includes = [];

        foreach ($normalizedEntries as $entry) {
            if ($entry['disposition'] !== 'include') {
                continue;
            }

            if (
                ! is_string($entry['logical_upload_filename'])
                || ! is_string($entry['resolved_date'])
                || ! is_string($entry['resolved_service'])
            ) {
                throw new RuntimeException("Validated include {$entry['relative_path']} is missing canonical identity fields.");
            }

            $includes[] = [
                'relative_path' => $entry['relative_path'],
                'sha256' => $entry['sha256'],
                'logical_upload_filename' => $entry['logical_upload_filename'],
                'resolved_date' => $entry['resolved_date'],
                'resolved_service' => $entry['resolved_service'],
                'alias_reason' => $entry['alias_reason'],
            ];
        }

        $manifestHash = CanonicalJson::hash([
            'format' => self::Format,
            'version' => self::Version,
            'entries' => $normalizedEntries,
        ]);
        $planHash = CanonicalJson::hash([
            'format' => 'crockenhill-openlp-import-plan',
            'version' => 1,
            'manifest_hash' => $manifestHash,
            'counts' => $counts,
            'includes' => $includes,
        ]);

        return new OpenLpCurationPlan($manifestHash, $planHash, $includes, $counts);
    }

    /** @return array<string, string> */
    private function inventory(string $rawRoot): array
    {
        $inventory = [];

        foreach (File::allFiles($rawRoot) as $file) {
            $realPath = $file->getRealPath();

            if (! is_string($realPath) || ! $this->isWithinRoot($realPath, $rawRoot)) {
                throw new RuntimeException("Raw OpenLP inventory escapes its root: {$file->getPathname()}");
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($rawRoot) + 1));
            $hash = hash_file('sha256', $realPath);

            if (! is_string($hash)) {
                throw new RuntimeException("Unable to hash raw OpenLP file: {$relativePath}");
            }

            $inventory[$relativePath] = $hash;
        }

        ksort($inventory, SORT_STRING);

        return $inventory;
    }

    /**
     * @param  list<mixed>  $entries
     * @return list<array{
     *     relative_path:string,
     *     sha256:string,
     *     disposition:string,
     *     duplicate_target_hash:?string,
     *     logical_upload_filename:?string,
     *     resolved_date:?string,
     *     resolved_service:?string,
     *     alias_reason:?string,
     *     exclusion_reason:?string
     * }>
     */
    private function normalizeEntries(array $entries, string $rawRoot): array
    {
        $normalized = [];
        $seenPaths = [];

        foreach ($entries as $offset => $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException("OpenLP curation entry {$offset} must be an object.");
            }

            $relativePath = $this->requiredString($entry, 'relative_path', $offset);
            $this->validateRelativePath($relativePath, $rawRoot);

            if (isset($seenPaths[$relativePath])) {
                throw new RuntimeException("Duplicate manifest path: {$relativePath}");
            }

            $seenPaths[$relativePath] = true;
            $sha256 = strtolower($this->requiredString($entry, 'sha256', $offset));

            if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
                throw new RuntimeException("Invalid SHA-256 for {$relativePath}.");
            }

            $disposition = $this->requiredString($entry, 'disposition', $offset);

            if (! in_array($disposition, ['include', 'duplicate-of', 'exclude'], true)) {
                throw new RuntimeException("Invalid disposition for {$relativePath}: {$disposition}");
            }

            $logicalFilename = $this->nullableString($entry, 'logical_upload_filename');
            $resolvedDate = $this->nullableString($entry, 'resolved_date');
            $resolvedService = $this->nullableString($entry, 'resolved_service');
            $aliasReason = $this->nullableString($entry, 'alias_reason');
            $exclusionReason = $this->nullableString($entry, 'exclusion_reason');
            $duplicateTargetHash = $this->nullableString($entry, 'duplicate_target_hash');

            if ($disposition === 'include') {
                $this->validateInclude(
                    $relativePath,
                    $logicalFilename,
                    $resolvedDate,
                    $resolvedService,
                    $aliasReason,
                    $exclusionReason,
                    $duplicateTargetHash,
                );
            }

            if ($disposition === 'duplicate-of' && $duplicateTargetHash === null) {
                throw new RuntimeException("Duplicate entry {$relativePath} must declare duplicate_target_hash.");
            }

            if ($disposition === 'duplicate-of' && $exclusionReason !== null) {
                throw new RuntimeException("Duplicate entry {$relativePath} cannot declare an exclusion reason.");
            }

            if ($disposition === 'exclude' && $exclusionReason === null) {
                throw new RuntimeException("Excluded entry {$relativePath} must declare exclusion_reason.");
            }

            if ($disposition === 'exclude' && $duplicateTargetHash !== null) {
                throw new RuntimeException("Excluded entry {$relativePath} cannot declare duplicate_target_hash.");
            }

            $normalized[] = [
                'relative_path' => $relativePath,
                'sha256' => $sha256,
                'disposition' => $disposition,
                'duplicate_target_hash' => $duplicateTargetHash === null ? null : strtolower($duplicateTargetHash),
                'logical_upload_filename' => $logicalFilename,
                'resolved_date' => $resolvedDate,
                'resolved_service' => $resolvedService,
                'alias_reason' => $aliasReason,
                'exclusion_reason' => $exclusionReason,
            ];
        }

        return $normalized;
    }

    private function validateRelativePath(string $relativePath, string $rawRoot): void
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

    private function validateInclude(
        string $relativePath,
        ?string $logicalFilename,
        ?string $resolvedDate,
        ?string $resolvedService,
        ?string $aliasReason,
        ?string $exclusionReason,
        ?string $duplicateTargetHash,
    ): void {
        if (
            $logicalFilename === null
            || basename($logicalFilename) !== $logicalFilename
            || strtolower(pathinfo($logicalFilename, PATHINFO_EXTENSION)) !== 'osz'
        ) {
            throw new RuntimeException("Included entry {$relativePath} has an invalid logical upload filename.");
        }

        if ($resolvedDate === null || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $resolvedDate) !== 1) {
            throw new RuntimeException("Included entry {$relativePath} has an invalid resolved date.");
        }

        $validServices = array_map(
            static fn (SermonService $service): string => $service->value,
            SermonService::cases(),
        );

        if ($resolvedService === null || ! in_array($resolvedService, $validServices, true)) {
            throw new RuntimeException("Included entry {$relativePath} has an invalid resolved service.");
        }

        $filenameIdentity = $this->parser->identityFromFilename($logicalFilename);

        if (
            $filenameIdentity === null
            || $filenameIdentity['date'] !== $resolvedDate
            || $filenameIdentity['service']->value !== $resolvedService
        ) {
            throw new RuntimeException("Included entry {$relativePath} has resolved identity fields that contradict its logical upload filename.");
        }

        $isAlias = $logicalFilename !== basename($relativePath);

        if ($isAlias !== ($aliasReason !== null)) {
            throw new RuntimeException("Included entry {$relativePath} has a contradictory alias.");
        }

        if ($exclusionReason !== null || $duplicateTargetHash !== null) {
            throw new RuntimeException("Included entry {$relativePath} has contradictory disposition fields.");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function validateDuplicateHashes(array $entries): void
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

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function validateLogicalIdentities(array $entries): void
    {
        $includes = collect($entries)->where('disposition', 'include');
        $duplicateFilenames = $includes->pluck('logical_upload_filename')->duplicates()->unique();

        if ($duplicateFilenames->isNotEmpty()) {
            throw new RuntimeException('Included entries contain duplicate logical upload filenames.');
        }

        $duplicateIdentities = $includes
            ->map(fn (array $entry): string => "{$entry['resolved_date']}\0{$entry['resolved_service']}")
            ->duplicates()
            ->unique();

        if ($duplicateIdentities->isNotEmpty()) {
            throw new RuntimeException('Included entries contain duplicate logical service identities.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function counts(array $entries): array
    {
        return [
            'raw' => count($entries),
            'include' => collect($entries)->where('disposition', 'include')->count(),
            'duplicate-of' => collect($entries)->where('disposition', 'duplicate-of')->count(),
            'exclude' => collect($entries)->where('disposition', 'exclude')->count(),
            'aliases' => collect($entries)->where('disposition', 'include')->whereNotNull('alias_reason')->count(),
        ];
    }

    /** @param array<string, mixed> $entry */
    private function requiredString(array $entry, string $key, int $offset): string
    {
        $value = $entry[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("OpenLP curation entry {$offset} requires {$key}.");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $entry */
    private function nullableString(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("OpenLP curation field {$key} must be a non-empty string or null.");
        }

        return trim($value);
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        return $path !== $root && str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
