<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\OpenLpCurationPlan;
use App\Enums\SermonService;
use App\Services\Song\OpenLpServiceParser;
use App\Support\CanonicalJson;
use App\Support\CurationManifestReader;
use Illuminate\Http\UploadedFile;
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

    private const Label = 'OpenLP';

    public function __construct(
        private readonly OpenLpServiceParser $parser,
        private readonly CurationManifestReader $reader,
    ) {}

    public function plan(string $rawDirectory, string $manifestPath): OpenLpCurationPlan
    {
        $rawRoot = $this->reader->requireDirectory($rawDirectory, self::Label);
        $envelope = $this->reader->envelope($manifestPath, self::Format, self::Version, self::Label);
        $batchKey = $envelope['batch_key'];
        $entries = $envelope['entries'];

        $inventory = $this->reader->inventory($rawRoot, self::Label);
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

            if ($this->reader->fileSize("{$rawRoot}/{$entry['relative_path']}") !== $entry['byte_size']) {
                throw new RuntimeException("Byte-size mismatch for {$entry['relative_path']}.");
            }
        }

        $this->reader->validateDuplicateHashes($normalizedEntries);
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
        $rawRoot = $this->reader->requireDirectory($rawDirectory, self::Label);

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
        $rawRoot = $this->reader->requireDirectory($rawDirectory, self::Label);

        return $this->verifiedIncludePath($rawRoot, $entry);
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

            $relativePath = $this->reader->requiredString($entry, 'relative_path', $offset, self::Label);
            $this->reader->validateRelativePath($relativePath, $rawRoot);

            if (isset($seenPaths[$relativePath])) {
                throw new RuntimeException("Duplicate manifest path: {$relativePath}");
            }

            $seenPaths[$relativePath] = true;

            /**
             * The item key is curation identity, deliberately independent of the
             * bytes it describes: two entries over the same content must remain
             * distinguishable to the durable per-item job lock downstream.
             */
            $itemKey = $this->reader->requiredString($entry, 'item_key', $offset, self::Label);

            if (isset($seenItemKeys[$itemKey])) {
                throw new RuntimeException("Duplicate manifest item key: {$itemKey}");
            }

            $seenItemKeys[$itemKey] = true;
            $sourceKind = $this->reader->requiredString($entry, 'source_kind', $offset, self::Label);

            if ($sourceKind !== self::SourceKind) {
                throw new RuntimeException(
                    "Entry {$relativePath} declares source_kind {$sourceKind}; this manifest curates ".self::SourceKind.'.'
                );
            }
            $sha256 = strtolower($this->reader->requiredString($entry, 'sha256', $offset, self::Label));
            $byteSize = $entry['byte_size'] ?? null;

            if (preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1 || ! is_int($byteSize) || $byteSize < 1) {
                throw new RuntimeException("Invalid SHA-256 for {$relativePath}.");
            }

            $disposition = $this->reader->requiredString($entry, 'disposition', $offset, self::Label);

            if (! in_array($disposition, CurationManifestReader::Dispositions, true)) {
                throw new RuntimeException("Invalid disposition for {$relativePath}: {$disposition}");
            }

            $logicalFilename = $this->reader->nullableString($entry, 'logical_upload_filename', self::Label);
            $resolvedDate = $this->reader->nullableString($entry, 'resolved_date', self::Label);
            $resolvedService = $this->reader->nullableString($entry, 'resolved_service', self::Label);
            $aliasReason = $this->reader->nullableString($entry, 'alias_reason', self::Label);
            $exclusionReason = $this->reader->nullableString($entry, 'exclusion_reason', self::Label);
            $duplicateTargetHash = $this->reader->nullableString($entry, 'duplicate_target_hash', self::Label);
            $parseDecision = $this->reader->nullableString($entry, 'parse_decision', self::Label);
            $concatenationDecision = $this->reader->nullableString($entry, 'concatenation_decision', self::Label);
            $expectedItemCount = $entry['expected_item_count'] ?? null;
            [$decidedBy, $decidedAt, $decisionRuleVersion] = $this->reader->curationAuthority($entry, $relativePath, self::Label);

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

    /**
     * @param  OpenLpCurationInclude  $entry
     */
    private function verifiedIncludePath(string $rawRoot, array $entry): string
    {
        return $this->reader->verifiedPath(
            $rawRoot,
            $entry['relative_path'],
            $entry['sha256'],
            $entry['byte_size'],
            self::Label,
        );
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
     * OpenLP's own logical-identity rule, deliberately *not* shared with Email:
     * one archive is one plan, so two included archives resolving to the same
     * date and service is a curation error. §7.5 explains why the Email corpus
     * must permit exactly that.
     *
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
}
