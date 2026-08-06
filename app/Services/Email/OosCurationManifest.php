<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosCurationPlan;
use App\Enums\SermonService;
use App\Services\ChurchService\OpenLpCurationManifest;
use App\Support\CanonicalJson;
use App\Support\CurationManifestReader;
use RuntimeException;

/**
 * §7.3/§7.5 curation authority for the Email order-of-service corpus.
 *
 * The third of the three §7.3 manifests. Two things make it structurally
 * different from {@see OpenLpCurationManifest}, and
 * both come from the corpus rather than from taste:
 *
 * **Two roots, one manifest.** The corpus is `storage/scratch/oos-verbatim/`
 * (raw email bodies) and `storage/scratch/oos/` (a formatting pass over them),
 * and the two do not correspond one-to-one: 247 entries are paired, 155 are
 * verbatim-only and 14 are formatted-only. §13.1 requires
 * `discovered = included + excluded` with every exclusion carrying a reason, so
 * a manifest over either root alone would leave a residual it could not name.
 * Every file in *both* roots must therefore appear in exactly one entry.
 *
 * **Several included entries may resolve to one service.** OpenLP forbids this:
 * one archive is one plan, so two archives resolving to the same date and
 * service is a curation error. Email is the opposite. A corrected order of
 * service supersedes its predecessor (§7.2 lineage), and a partial hymn list
 * complements a full order without superseding anything. Both are legitimate,
 * so the invariant here is not uniqueness but *exactly one active leaf per
 * service among full orders*, with partials outside the chain entirely.
 *
 * @phpstan-import-type OosCurationInclude from OosCurationPlan
 */
class OosCurationManifest
{
    private const Format = 'crockenhill-oos-curation';

    private const Version = 1;

    /** This manifest curates one source kind; the format is shared with OpenLP and livestream. */
    private const SourceKind = 'email';

    private const Label = 'OoS';

    /**
     * Shared vocabulary with OpenLP. `strict` requires the parse to agree with
     * the file's own frontmatter; `manifest-authoritative` records that an
     * operator has already adjudicated a disagreement.
     */
    private const ParseDecisions = ['strict', 'manifest-authoritative'];

    /**
     * `explicit` — the source states its own date. `inferred` — the date was
     * derived, and §7.3 makes the manifest rather than the derivation
     * authoritative. Nine files in the corpus carry
     * `date_source: derived from liturgical calendar`; each becomes `inferred`
     * with the derivation recorded as its reason.
     */
    private const DateDecisions = ['explicit', 'inferred'];

    /**
     * `full` — the entry asserts a complete order of service. `partial` — it
     * asserts only part of one (the `-details`, `-hymns` and `-songs` files).
     *
     * The distinction is load-bearing for §8.4: silence in a partial is not
     * disagreement. A hymn list that names no sermon must never be read as
     * asserting the service had none.
     */
    private const ContentScopes = ['full', 'partial'];

    private const Payloads = ['formatted', 'verbatim'];

    public function __construct(
        private readonly CurationManifestReader $reader,
    ) {}

    public function plan(string $verbatimDirectory, string $formattedDirectory, string $manifestPath): OosCurationPlan
    {
        $verbatimRoot = $this->reader->requireDirectory($verbatimDirectory, self::Label.' verbatim');
        $formattedRoot = $this->reader->requireDirectory($formattedDirectory, self::Label.' formatted');

        if ($verbatimRoot === $formattedRoot) {
            throw new RuntimeException('The OoS verbatim and formatted roots must be distinct directories.');
        }

        $envelope = $this->reader->envelope($manifestPath, self::Format, self::Version, self::Label);
        $batchKey = $envelope['batch_key'];

        $verbatimInventory = $this->reader->inventory($verbatimRoot, self::Label.' verbatim');
        $formattedInventory = $this->reader->inventory($formattedRoot, self::Label.' formatted');

        $normalizedEntries = $this->normalizeEntries($envelope['entries'], $verbatimRoot, $formattedRoot);
        usort(
            $normalizedEntries,
            static fn (array $left, array $right): int => $left['item_key'] <=> $right['item_key'],
        );

        $this->reconcileRoot($normalizedEntries, $verbatimInventory, 'verbatim_relative_path', 'verbatim_sha256', 'verbatim');
        $this->reconcileRoot($normalizedEntries, $formattedInventory, 'formatted_relative_path', 'formatted_sha256', 'formatted');

        $this->reader->validateDuplicateHashes($normalizedEntries);
        $this->validateSupersessionChains($normalizedEntries);

        $counts = $this->counts($normalizedEntries);
        $includes = $this->includes($normalizedEntries);

        $manifestHash = CanonicalJson::hash([
            'format' => self::Format,
            'version' => self::Version,
            'batch_key' => $batchKey,
            'entries' => $normalizedEntries,
        ]);
        $planHash = CanonicalJson::hash([
            'format' => 'crockenhill-oos-import-plan',
            'version' => 1,
            'batch_key' => $batchKey,
            'manifest_hash' => $manifestHash,
            'counts' => $counts,
            'includes' => $includes,
        ]);

        return new OosCurationPlan($manifestHash, $planHash, $includes, $counts, $batchKey);
    }

    /**
     * Re-verify every approved payload's bytes immediately before use. §7.4
     * requires the recheck under the apply lock, not only at planning time.
     *
     * @return array<string, string> item key => absolute payload path
     */
    public function verifyIncludes(string $verbatimDirectory, string $formattedDirectory, OosCurationPlan $plan): array
    {
        $verbatimRoot = $this->reader->requireDirectory($verbatimDirectory, self::Label.' verbatim');
        $formattedRoot = $this->reader->requireDirectory($formattedDirectory, self::Label.' formatted');
        $paths = [];

        foreach ($plan->includes as $entry) {
            $root = $entry['payload'] === 'formatted' ? $formattedRoot : $verbatimRoot;

            $paths[$entry['item_key']] = $this->reader->verifiedPath(
                $root,
                $entry['relative_path'],
                $entry['sha256'],
                $entry['byte_size'],
                self::Label,
            );
        }

        return $paths;
    }

    /**
     * Every file in a root must be claimed by exactly one entry, and every
     * claimed path must exist with the approved bytes. This is §13.1's
     * `discovered = included + excluded` made falsifiable: an unmanifested file
     * is discovered material nobody has ruled on.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, string>  $inventory
     */
    private function reconcileRoot(array $entries, array $inventory, string $pathKey, string $hashKey, string $rootLabel): void
    {
        $claimed = [];

        foreach ($entries as $entry) {
            $path = $entry[$pathKey];

            if ($path === null) {
                continue;
            }

            if (isset($claimed[$path])) {
                throw new RuntimeException("Two OoS entries claim the same {$rootLabel} path: {$path}");
            }

            $claimed[$path] = true;

            if (! array_key_exists($path, $inventory)) {
                throw new RuntimeException("OoS curation manifest references a missing {$rootLabel} file: {$path}");
            }

            if (! hash_equals($entry[$hashKey], $inventory[$path])) {
                throw new RuntimeException("SHA-256 mismatch for {$rootLabel} file {$path}.");
            }
        }

        $unmanifested = array_values(array_diff(array_keys($inventory), array_keys($claimed)));

        if ($unmanifested !== []) {
            throw new RuntimeException(
                "The OoS {$rootLabel} directory contains unmanifested files: ".implode(', ', $unmanifested)
            );
        }
    }

    /**
     * §7.2: the active revision is the unique leaf of an explicit supersession
     * chain. Here that is enforced per resolved service, over full orders only.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function validateSupersessionChains(array $entries): void
    {
        $includes = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['disposition'] === 'include',
        ));
        $byKey = [];

        foreach ($includes as $entry) {
            $byKey[$entry['item_key']] = $entry;
        }

        $supersededBy = [];

        foreach ($includes as $entry) {
            $target = $entry['supersedes'];

            if ($target === null) {
                continue;
            }

            if ($entry['content_scope'] !== 'full') {
                throw new RuntimeException(
                    "Entry {$entry['item_key']} is a partial order and cannot supersede anything; ".
                    'a partial complements a service rather than replacing its order.'
                );
            }

            if ($target === $entry['item_key']) {
                throw new RuntimeException("Entry {$entry['item_key']} supersedes itself.");
            }

            if (! isset($byKey[$target])) {
                throw new RuntimeException(
                    "Entry {$entry['item_key']} supersedes {$target}, which is not an included entry."
                );
            }

            if ($byKey[$target]['content_scope'] !== 'full') {
                throw new RuntimeException(
                    "Entry {$entry['item_key']} supersedes partial entry {$target}; only a full order supersedes a full order."
                );
            }

            if (
                $byKey[$target]['resolved_date'] !== $entry['resolved_date']
                || $byKey[$target]['resolved_service'] !== $entry['resolved_service']
            ) {
                throw new RuntimeException(
                    "Entry {$entry['item_key']} supersedes {$target}, which resolves to a different service."
                );
            }

            if (isset($supersededBy[$target])) {
                throw new RuntimeException(
                    "Entry {$target} is superseded by both {$supersededBy[$target]} and {$entry['item_key']}; ".
                    'a supersession chain forks.'
                );
            }

            $supersededBy[$target] = $entry['item_key'];
        }

        $this->rejectCycles($includes, $byKey);

        $leavesByService = [];

        foreach ($includes as $entry) {
            if ($entry['content_scope'] !== 'full' || isset($supersededBy[$entry['item_key']])) {
                continue;
            }

            $identity = "{$entry['resolved_date']}\0{$entry['resolved_service']}";
            $leavesByService[$identity][] = $entry['item_key'];
        }

        foreach ($leavesByService as $identity => $leaves) {
            if (count($leaves) > 1) {
                [$date, $service] = explode("\0", (string) $identity);
                sort($leaves);

                throw new RuntimeException(
                    "The {$date} {$service} service has more than one active full order: ".implode(', ', $leaves).'. '.
                    'Record which supersedes which, or mark the complementary ones content_scope partial.'
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $includes
     * @param  array<string, array<string, mixed>>  $byKey
     */
    private function rejectCycles(array $includes, array $byKey): void
    {
        foreach ($includes as $entry) {
            $seen = [$entry['item_key'] => true];
            $cursor = $entry['supersedes'];

            while ($cursor !== null) {
                if (isset($seen[$cursor])) {
                    throw new RuntimeException(
                        "Supersession chain containing {$entry['item_key']} is cyclic."
                    );
                }

                $seen[$cursor] = true;
                $cursor = $byKey[$cursor]['supersedes'] ?? null;
            }
        }
    }

    /**
     * @param  list<mixed>  $entries
     * @return list<array<string, mixed>>
     */
    private function normalizeEntries(array $entries, string $verbatimRoot, string $formattedRoot): array
    {
        $normalized = [];
        $seenItemKeys = [];

        foreach ($entries as $offset => $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException("OoS curation entry {$offset} must be an object.");
            }

            $itemKey = $this->reader->requiredString($entry, 'item_key', $offset, self::Label);

            if (isset($seenItemKeys[$itemKey])) {
                throw new RuntimeException("Duplicate manifest item key: {$itemKey}");
            }

            $seenItemKeys[$itemKey] = true;

            $sourceKind = $this->reader->requiredString($entry, 'source_kind', $offset, self::Label);

            if ($sourceKind !== self::SourceKind) {
                throw new RuntimeException(
                    "Entry {$itemKey} declares source_kind {$sourceKind}; this manifest curates ".self::SourceKind.'.'
                );
            }

            [$verbatimPath, $verbatimHash, $verbatimSize] = $this->sourceFile($entry, $itemKey, 'verbatim', $verbatimRoot);
            [$formattedPath, $formattedHash, $formattedSize] = $this->sourceFile($entry, $itemKey, 'formatted', $formattedRoot);

            if ($verbatimPath === null && $formattedPath === null) {
                throw new RuntimeException(
                    "Entry {$itemKey} declares neither a verbatim nor a formatted file."
                );
            }

            $verbatimAbsenceReason = $this->reader->nullableString($entry, 'verbatim_absence_reason', self::Label);

            if (($verbatimPath === null) !== ($verbatimAbsenceReason !== null)) {
                throw new RuntimeException(
                    "Entry {$itemKey} must declare verbatim_absence_reason exactly when it has no verbatim file; ".
                    'a formatted order with no raw counterpart has an unverifiable provenance chain.'
                );
            }

            $disposition = $this->reader->requiredString($entry, 'disposition', $offset, self::Label);

            if (! in_array($disposition, CurationManifestReader::Dispositions, true)) {
                throw new RuntimeException("Invalid disposition for {$itemKey}: {$disposition}");
            }

            $primaryPath = $formattedPath ?? $verbatimPath;
            $primaryHash = $formattedPath === null ? $verbatimHash : $formattedHash;
            $primarySize = $formattedPath === null ? $verbatimSize : $formattedSize;

            [$decidedBy, $decidedAt, $decisionRuleVersion] = $this->reader->curationAuthority($entry, $itemKey, self::Label);

            $normalizedEntry = [
                'item_key' => $itemKey,
                'source_kind' => $sourceKind,
                'relative_path' => $primaryPath,
                'sha256' => $primaryHash,
                'byte_size' => $primarySize,
                'verbatim_relative_path' => $verbatimPath,
                'verbatim_sha256' => $verbatimHash,
                'verbatim_byte_size' => $verbatimSize,
                'verbatim_absence_reason' => $verbatimAbsenceReason,
                'formatted_relative_path' => $formattedPath,
                'formatted_sha256' => $formattedHash,
                'formatted_byte_size' => $formattedSize,
                'disposition' => $disposition,
                'duplicate_target_hash' => $this->reader->nullableString($entry, 'duplicate_target_hash', self::Label),
                'exclusion_reason' => $this->reader->nullableString($entry, 'exclusion_reason', self::Label),
                'payload' => $this->reader->nullableString($entry, 'payload', self::Label),
                'resolved_date' => $this->reader->nullableString($entry, 'resolved_date', self::Label),
                'resolved_service' => $this->reader->nullableString($entry, 'resolved_service', self::Label),
                'service_label' => $this->reader->nullableString($entry, 'service_label', self::Label),
                'title_override' => $this->reader->nullableString($entry, 'title_override', self::Label),
                'date_decision' => $this->reader->nullableString($entry, 'date_decision', self::Label),
                'date_decision_reason' => $this->reader->nullableString($entry, 'date_decision_reason', self::Label),
                'content_scope' => $this->reader->nullableString($entry, 'content_scope', self::Label),
                'partial_scope_reason' => $this->reader->nullableString($entry, 'partial_scope_reason', self::Label),
                'supersedes' => $this->reader->nullableString($entry, 'supersedes', self::Label),
                'parse_decision' => $this->reader->nullableString($entry, 'parse_decision', self::Label),
                'expected_item_count' => $entry['expected_item_count'] ?? null,
                'decided_by' => $decidedBy,
                'decided_at' => $decidedAt,
                'decision_rule_version' => $decisionRuleVersion,
            ];

            $disposition === 'include'
                ? $this->validateInclude($normalizedEntry, $verbatimPath, $formattedPath)
                : $this->validateNonInclude($normalizedEntry);

            $normalized[] = $normalizedEntry;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0:?string,1:?string,2:?int}
     */
    private function sourceFile(array $entry, string $itemKey, string $which, string $root): array
    {
        $path = $this->reader->nullableString($entry, "{$which}_relative_path", self::Label);
        $hash = $this->reader->nullableString($entry, "{$which}_sha256", self::Label);
        $size = $entry["{$which}_byte_size"] ?? null;

        if ($path === null) {
            if ($hash !== null || $size !== null) {
                throw new RuntimeException("Entry {$itemKey} declares {$which} bytes without a {$which} path.");
            }

            return [null, null, null];
        }

        $this->reader->validateRelativePath($path, $root);
        $hash = $hash === null ? null : strtolower($hash);

        if ($hash === null || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1 || ! is_int($size) || $size < 1) {
            throw new RuntimeException("Entry {$itemKey} has an invalid {$which} SHA-256 or byte size.");
        }

        return [$path, $hash, $size];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function validateInclude(array $entry, ?string $verbatimPath, ?string $formattedPath): void
    {
        $itemKey = $entry['item_key'];

        if ($entry['exclusion_reason'] !== null || $entry['duplicate_target_hash'] !== null) {
            throw new RuntimeException("Included entry {$itemKey} has contradictory disposition fields.");
        }

        if ($entry['payload'] === null || ! in_array($entry['payload'], self::Payloads, true)) {
            throw new RuntimeException(
                "Included entry {$itemKey} requires a payload of ".implode(' or ', self::Payloads).'.'
            );
        }

        $payloadPath = $entry['payload'] === 'formatted' ? $formattedPath : $verbatimPath;

        if ($payloadPath === null) {
            throw new RuntimeException(
                "Included entry {$itemKey} nominates the {$entry['payload']} payload but declares no such file."
            );
        }

        if ($entry['resolved_date'] === null || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $entry['resolved_date']) !== 1) {
            throw new RuntimeException("Included entry {$itemKey} has an invalid resolved date.");
        }

        $validServices = array_map(
            static fn (SermonService $service): string => $service->value,
            SermonService::cases(),
        );

        if ($entry['resolved_service'] === null || ! in_array($entry['resolved_service'], $validServices, true)) {
            throw new RuntimeException(
                "Included entry {$itemKey} has an invalid resolved service; the corpus's free-text service ".
                'frontmatter must be resolved to '.implode(', ', $validServices).'.'
            );
        }

        /**
         * `other` is the enum's catch-all, so on its own it loses which service
         * this was. Good Friday, Carols by Candlelight and a baptismal service
         * all land there and must stay distinguishable.
         */
        if (($entry['resolved_service'] === SermonService::Other->value) !== ($entry['service_label'] !== null)) {
            throw new RuntimeException(
                "Included entry {$itemKey} must declare service_label exactly when it resolves to ".
                SermonService::Other->value.'.'
            );
        }

        $this->requireDecision($entry, 'date_decision', self::DateDecisions, $itemKey);
        $this->requireDecision($entry, 'content_scope', self::ContentScopes, $itemKey);
        $this->requireDecision($entry, 'parse_decision', self::ParseDecisions, $itemKey);

        if (($entry['date_decision'] === 'inferred') !== ($entry['date_decision_reason'] !== null)) {
            throw new RuntimeException(
                "Included entry {$itemKey} must declare date_decision_reason exactly when its date is inferred."
            );
        }

        if (($entry['content_scope'] === 'partial') !== ($entry['partial_scope_reason'] !== null)) {
            throw new RuntimeException(
                "Included entry {$itemKey} must declare partial_scope_reason exactly when its content scope is partial."
            );
        }

        if (! is_int($entry['expected_item_count']) || $entry['expected_item_count'] < 0) {
            throw new RuntimeException("Included entry {$itemKey} requires a non-negative expected_item_count.");
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $allowed
     */
    private function requireDecision(array $entry, string $key, array $allowed, string $itemKey): void
    {
        if ($entry[$key] === null || ! in_array($entry[$key], $allowed, true)) {
            throw new RuntimeException(
                "Included entry {$itemKey} requires a {$key} of ".implode(' or ', $allowed).'.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function validateNonInclude(array $entry): void
    {
        $itemKey = $entry['item_key'];
        $includeOnly = [
            'payload', 'resolved_date', 'resolved_service', 'service_label', 'title_override',
            'date_decision', 'date_decision_reason', 'content_scope', 'partial_scope_reason',
            'supersedes', 'parse_decision', 'expected_item_count',
        ];

        foreach ($includeOnly as $key) {
            if ($entry[$key] !== null) {
                throw new RuntimeException(
                    "Entry {$itemKey} is not imported and has contradictory disposition fields: ".
                    "{$key} applies to includes only."
                );
            }
        }

        if ($entry['disposition'] === 'duplicate-of') {
            if ($entry['duplicate_target_hash'] === null) {
                throw new RuntimeException("Duplicate entry {$itemKey} must declare duplicate_target_hash.");
            }

            if ($entry['exclusion_reason'] !== null) {
                throw new RuntimeException("Duplicate entry {$itemKey} cannot declare an exclusion reason.");
            }
        }

        if ($entry['disposition'] === 'exclude') {
            if ($entry['exclusion_reason'] === null) {
                throw new RuntimeException("Excluded entry {$itemKey} must declare exclusion_reason.");
            }

            if ($entry['duplicate_target_hash'] !== null) {
                throw new RuntimeException("Excluded entry {$itemKey} cannot declare duplicate_target_hash.");
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<OosCurationInclude>
     */
    private function includes(array $entries): array
    {
        $includes = [];

        foreach ($entries as $entry) {
            if ($entry['disposition'] !== 'include') {
                continue;
            }

            /**
             * validateInclude() has already proven each of these, so a failure
             * here means normalisation and validation have drifted apart rather
             * than that a manifest is bad. Re-checking keeps the transported
             * shape honest instead of asserting it.
             */
            foreach (['payload', 'resolved_date', 'resolved_service', 'date_decision', 'content_scope', 'parse_decision'] as $key) {
                if (! is_string($entry[$key])) {
                    throw new RuntimeException("Validated include {$entry['item_key']} is missing {$key}.");
                }
            }

            if (! is_int($entry['expected_item_count'])) {
                throw new RuntimeException("Validated include {$entry['item_key']} is missing expected_item_count.");
            }

            $payloadPath = $entry['payload'] === 'formatted'
                ? $entry['formatted_relative_path']
                : $entry['verbatim_relative_path'];
            $payloadHash = $entry['payload'] === 'formatted'
                ? $entry['formatted_sha256']
                : $entry['verbatim_sha256'];
            $payloadSize = $entry['payload'] === 'formatted'
                ? $entry['formatted_byte_size']
                : $entry['verbatim_byte_size'];

            if (! is_string($payloadPath) || ! is_string($payloadHash) || ! is_int($payloadSize)) {
                throw new RuntimeException("Validated include {$entry['item_key']} has no resolvable payload file.");
            }

            $includes[] = [
                'item_key' => $entry['item_key'],
                'source_kind' => $entry['source_kind'],
                'relative_path' => $payloadPath,
                'sha256' => $payloadHash,
                'byte_size' => $payloadSize,
                'payload' => $entry['payload'],
                'verbatim_relative_path' => $entry['verbatim_relative_path'],
                'formatted_relative_path' => $entry['formatted_relative_path'],
                'resolved_date' => $entry['resolved_date'],
                'resolved_service' => $entry['resolved_service'],
                'service_label' => $entry['service_label'],
                'title_override' => $entry['title_override'],
                'date_decision' => $entry['date_decision'],
                'date_decision_reason' => $entry['date_decision_reason'],
                'content_scope' => $entry['content_scope'],
                'partial_scope_reason' => $entry['partial_scope_reason'],
                'supersedes' => $entry['supersedes'],
                'parse_decision' => $entry['parse_decision'],
                'expected_item_count' => $entry['expected_item_count'],
                'decided_by' => $entry['decided_by'],
                'decided_at' => $entry['decided_at'],
                'decision_rule_version' => $entry['decision_rule_version'],
            ];
        }

        return $includes;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, int>
     */
    private function counts(array $entries): array
    {
        $collection = collect($entries);
        $includes = $collection->where('disposition', 'include');

        return [
            'raw' => $collection->count(),
            'include' => $includes->count(),
            'duplicate-of' => $collection->where('disposition', 'duplicate-of')->count(),
            'exclude' => $collection->where('disposition', 'exclude')->count(),
            'full' => $includes->where('content_scope', 'full')->count(),
            'partial' => $includes->where('content_scope', 'partial')->count(),
            'superseded' => $includes->whereNotNull('supersedes')->count(),
            'inferred-date' => $includes->where('date_decision', 'inferred')->count(),
            'verbatim-only' => $collection->whereNull('formatted_relative_path')->count(),
            'formatted-only' => $collection->whereNull('verbatim_relative_path')->count(),
        ];
    }
}
