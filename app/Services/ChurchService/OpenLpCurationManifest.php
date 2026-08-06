<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\OpenLpCurationPlan;
use App\Enums\SermonService;
use App\Services\Song\OpenLpServiceParser;
use App\Support\CanonicalJson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;

/**
 * @phpstan-import-type OpenLpCurationInclude from \App\Data\OpenLpCurationPlan
 */
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

    /**
     * Version 2 adds §7.3's curation-authority fields (stable item key, source
     * kind, parse/concatenation decision, expected occurrence count and the
     * decision author/time or approved rule version). A v1 manifest carries no
     * attribution at all, so it is rejected rather than defaulted.
     */
    private const Version = 2;

    /** This manifest curates one source kind; the format is shared with Email and livestream. */
    private const SourceKind = 'openlp';

    private const ParseDecisions = ['strict', 'manifest-authoritative'];

    /**
     * Shared with the historic video dispatch vocabulary. A `.osz` archive is a
     * single file, so only `none` is ever valid here.
     */
    private const ConcatenationDecisions = ['none', 'lossless', 'reencoded'];

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

        $batchKey = $manifest['batch_key'] ?? null;

        if (! is_string($batchKey) || trim($batchKey) === '') {
            throw new RuntimeException('The OpenLP curation manifest requires a non-empty batch_key.');
        }

        $batchKey = trim($batchKey);
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

            if ($this->fileSize("{$rawRoot}/{$entry['relative_path']}") !== $entry['byte_size']) {
                throw new RuntimeException("Byte-size mismatch for {$entry['relative_path']}.");
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

            if (
                ! is_string($entry['parse_decision'])
                || ! is_string($entry['concatenation_decision'])
                || ! is_int($entry['expected_item_count'])
            ) {
                throw new RuntimeException("Validated include {$entry['relative_path']} is missing curation decision fields.");
            }

            $includes[] = [
                'item_key' => $entry['item_key'],
                'source_kind' => $entry['source_kind'],
                'relative_path' => $entry['relative_path'],
                'sha256' => $entry['sha256'],
                'byte_size' => $entry['byte_size'],
                'logical_upload_filename' => $entry['logical_upload_filename'],
                'resolved_date' => $entry['resolved_date'],
                'resolved_service' => $entry['resolved_service'],
                'alias_reason' => $entry['alias_reason'],
                'parse_decision' => $entry['parse_decision'],
                'concatenation_decision' => $entry['concatenation_decision'],
                'expected_item_count' => $entry['expected_item_count'],
                'decided_by' => $entry['decided_by'],
                'decided_at' => $entry['decided_at'],
                'decision_rule_version' => $entry['decision_rule_version'],
            ];
        }

        $manifestHash = CanonicalJson::hash([
            'format' => self::Format,
            'version' => self::Version,
            'batch_key' => $batchKey,
            'entries' => $normalizedEntries,
        ]);
        $planHash = CanonicalJson::hash([
            'format' => 'crockenhill-openlp-import-plan',
            'version' => 2,
            'batch_key' => $batchKey,
            'manifest_hash' => $manifestHash,
            'counts' => $counts,
            'includes' => $includes,
        ]);

        return new OpenLpCurationPlan($manifestHash, $planHash, $includes, $counts, $batchKey);
    }

    /**
     * @return array<string, string>
     */
    public function verifyIncludes(string $rawDirectory, OpenLpCurationPlan $plan): array
    {
        $rawRoot = realpath($rawDirectory);

        if (! is_string($rawRoot) || ! is_dir($rawRoot)) {
            throw new RuntimeException("Raw OpenLP directory does not exist: {$rawDirectory}");
        }

        $paths = [];

        foreach ($plan->includes as $entry) {
            $paths[$entry['relative_path']] = $this->verifiedIncludePath($rawRoot, $entry);
        }

        return $paths;
    }

    /**
     * Parse every approved archive before an operator receives an applyable plan,
     * and reconcile the parse against the curation decisions that authorised it.
     */
    public function validateIncludesForDryRun(string $rawDirectory, OpenLpCurationPlan $plan): void
    {
        foreach ($plan->includes as $entry) {
            $path = $this->verifyInclude($rawDirectory, $entry);
            $parsed = $this->parser->parse(new UploadedFile(
                path: $path,
                originalName: $entry['logical_upload_filename'],
                mimeType: 'application/zip',
                test: true,
            ));

            if ($parsed->date !== $entry['resolved_date'] || $parsed->service->value !== $entry['resolved_service']) {
                throw new RuntimeException("Parsed OpenLP archive identity contradicts its approved manifest entry: {$entry['relative_path']}.");
            }

            /**
             * The archive's embedded .osj name disagreeing with the approved
             * logical filename is exactly the corrected-filename case. Under
             * `strict` it is unadjudicated and fails closed; under
             * `manifest-authoritative` the operator has already ruled on it.
             */
            if (
                $entry['parse_decision'] === 'strict'
                && ($parsed->importMetadata['filename_mismatch'] ?? false) === true
            ) {
                throw new RuntimeException(
                    'Approved OpenLP archive has an embedded .osj identity that contradicts its logical upload '.
                    "filename: {$entry['relative_path']}. Record parse_decision manifest-authoritative to accept it."
                );
            }

            $parsedItemCount = count($parsed->items);

            if ($parsedItemCount !== $entry['expected_item_count']) {
                throw new RuntimeException(
                    "Approved OpenLP archive {$entry['relative_path']} parsed {$parsedItemCount} items but its ".
                    "manifest expected item count is {$entry['expected_item_count']}."
                );
            }
        }
    }

    /**
     * @param  OpenLpCurationInclude  $entry
     */
    public function verifyInclude(string $rawDirectory, array $entry): string
    {
        $rawRoot = realpath($rawDirectory);

        if (! is_string($rawRoot) || ! is_dir($rawRoot)) {
            throw new RuntimeException("Raw OpenLP directory does not exist: {$rawDirectory}");
        }

        return $this->verifiedIncludePath($rawRoot, $entry);
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
     *     item_key:string,
     *     source_kind:string,
     *     relative_path:string,
     *     sha256:string,
     *     byte_size:int,
     *     disposition:string,
     *     duplicate_target_hash:?string,
     *     logical_upload_filename:?string,
     *     resolved_date:?string,
     *     resolved_service:?string,
     *     alias_reason:?string,
     *     exclusion_reason:?string,
     *     parse_decision:?string,
     *     concatenation_decision:?string,
     *     expected_item_count:?int,
     *     decided_by:?string,
     *     decided_at:?string,
     *     decision_rule_version:?string
     * }>
     */
    private function normalizeEntries(array $entries, string $rawRoot): array
    {
        $normalized = [];
        $seenPaths = [];
        $seenItemKeys = [];

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

            /**
             * The item key is curation identity, deliberately independent of the
             * bytes it describes: two entries over the same content must remain
             * distinguishable to the durable per-item job lock downstream.
             */
            $itemKey = $this->requiredString($entry, 'item_key', $offset);

            if (isset($seenItemKeys[$itemKey])) {
                throw new RuntimeException("Duplicate manifest item key: {$itemKey}");
            }

            $seenItemKeys[$itemKey] = true;
            $sourceKind = $this->requiredString($entry, 'source_kind', $offset);

            if ($sourceKind !== self::SourceKind) {
                throw new RuntimeException(
                    "Entry {$relativePath} declares source_kind {$sourceKind}; this manifest curates ".self::SourceKind.'.'
                );
            }
            $sha256 = strtolower($this->requiredString($entry, 'sha256', $offset));
            $byteSize = $entry['byte_size'] ?? null;

            if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1 || ! is_int($byteSize) || $byteSize < 1) {
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
            $parseDecision = $this->nullableString($entry, 'parse_decision');
            $concatenationDecision = $this->nullableString($entry, 'concatenation_decision');
            $expectedItemCount = $entry['expected_item_count'] ?? null;
            [$decidedBy, $decidedAt, $decisionRuleVersion] = $this->curationAuthority($entry, $relativePath);

            if ($expectedItemCount !== null && (! is_int($expectedItemCount) || $expectedItemCount < 0)) {
                throw new RuntimeException("Entry {$relativePath} has an invalid expected_item_count.");
            }

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
                $this->validateIncludeCurationDecisions(
                    $relativePath,
                    $parseDecision,
                    $concatenationDecision,
                    $expectedItemCount,
                );
            } elseif ($parseDecision !== null || $concatenationDecision !== null || $expectedItemCount !== null) {
                throw new RuntimeException(
                    "Entry {$relativePath} is not imported and has contradictory disposition fields: ".
                    'parse, concatenation and expected-count decisions apply to includes only.'
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
                'item_key' => $itemKey,
                'source_kind' => $sourceKind,
                'relative_path' => $relativePath,
                'sha256' => $sha256,
                'byte_size' => $byteSize,
                'disposition' => $disposition,
                'duplicate_target_hash' => $duplicateTargetHash === null ? null : strtolower($duplicateTargetHash),
                'logical_upload_filename' => $logicalFilename,
                'resolved_date' => $resolvedDate,
                'resolved_service' => $resolvedService,
                'alias_reason' => $aliasReason,
                'exclusion_reason' => $exclusionReason,
                'parse_decision' => $parseDecision,
                'concatenation_decision' => $concatenationDecision,
                'expected_item_count' => $expectedItemCount,
                'decided_by' => $decidedBy,
                'decided_at' => $decidedAt,
                'decision_rule_version' => $decisionRuleVersion,
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

    /**
     * @param  OpenLpCurationInclude  $entry
     */
    private function verifiedIncludePath(string $rawRoot, array $entry): string
    {
        $relativePath = $entry['relative_path'];
        $this->validateRelativePath($relativePath, $rawRoot);
        $path = "{$rawRoot}/{$relativePath}";

        if (! is_file($path) || $this->containsSymlink($rawRoot, $relativePath)) {
            throw new RuntimeException("Approved OpenLP archive is missing or symlinked: {$relativePath}");
        }

        $realPath = realpath($path);

        if (! is_string($realPath) || ! $this->isWithinRoot($realPath, $rawRoot)) {
            throw new RuntimeException("Approved OpenLP archive escapes the raw directory: {$relativePath}");
        }

        if ($this->fileSize($realPath) !== $entry['byte_size']) {
            throw new RuntimeException("Byte-size mismatch for {$relativePath}.");
        }

        $hash = hash_file('sha256', $realPath);

        if (! is_string($hash) || ! hash_equals($entry['sha256'], $hash)) {
            throw new RuntimeException("SHA-256 mismatch for {$relativePath}.");
        }

        return $realPath;
    }

    private function containsSymlink(string $rawRoot, string $relativePath): bool
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

    private function fileSize(string $path): int
    {
        $size = filesize($path);

        if (! is_int($size)) {
            throw new RuntimeException("Unable to determine file size: {$path}");
        }

        return $size;
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
     * §7.3 requires every entry to record who decided it and when, or the
     * approved rule version that decided it. Without one of those the manifest
     * is a cache of a filename heuristic rather than mutation authority.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0:?string,1:?string,2:?string}
     */
    private function curationAuthority(array $entry, string $relativePath): array
    {
        $decidedBy = $this->nullableString($entry, 'decided_by');
        $decidedAt = $this->nullableString($entry, 'decided_at');
        $decisionRuleVersion = $this->nullableString($entry, 'decision_rule_version');

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

    private function isoTimestamp(string $value): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);

        if ($parsed === false || $parsed->format(\DateTimeInterface::ATOM) !== $value) {
            return null;
        }

        return $value;
    }

    private function validateIncludeCurationDecisions(
        string $relativePath,
        ?string $parseDecision,
        ?string $concatenationDecision,
        ?int $expectedItemCount,
    ): void {
        if ($parseDecision === null || ! in_array($parseDecision, self::ParseDecisions, true)) {
            throw new RuntimeException(
                "Included entry {$relativePath} requires a parse_decision of ".implode(' or ', self::ParseDecisions).'.'
            );
        }

        if ($concatenationDecision === null || ! in_array($concatenationDecision, self::ConcatenationDecisions, true)) {
            throw new RuntimeException(
                "Included entry {$relativePath} requires a concatenation_decision of ".
                implode(', ', self::ConcatenationDecisions).'.'
            );
        }

        if ($concatenationDecision !== 'none') {
            throw new RuntimeException(
                "Included entry {$relativePath} declares concatenation_decision {$concatenationDecision}; ".
                'a single OpenLP archive is never concatenated.'
            );
        }

        if ($expectedItemCount === null) {
            throw new RuntimeException(
                "Included entry {$relativePath} requires an expected_item_count."
            );
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
