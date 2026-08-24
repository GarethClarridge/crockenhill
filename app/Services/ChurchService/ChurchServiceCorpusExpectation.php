<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceSourceRecord;
use App\Services\Email\OosApprovedCorpus;
use App\Services\Email\OosArchiveEvaluator;
use App\Services\Email\OosCurationEntryFactory;
use App\Support\CanonicalJson;
use RuntimeException;

/**
 * Reconciles what an approved curation manifest says the corpus is against what
 * actually staged — the F1 rule, and the half of RG-A that
 * {@see ChurchServiceCorpusMembership} structurally cannot answer.
 *
 * Membership certification asks "is every item in this declared set staged
 * correctly", which is exactly right for lineage, hashes and projection but
 * silent about anything the declared set omits. Its only producer read the set
 * out of the database, so an approved entry that held rather than imported was
 * missing from both sides and nothing failed. This class takes the expectation
 * from {@see OosApprovedCorpus} — derived from the manifest and nothing else —
 * and asks the two questions the other direction:
 *
 * - **Did every approved entry stage?** A held entry is now a named blocker
 *   rather than an absence, unless the manifest's producer carries an operator's
 *   written acceptance of that hold.
 * - **Does anything staged have no approved origin?** F1 permits extra
 *   identities only where an approved entry explains them, and fails closed on
 *   unexplained excess.
 *
 * The second question needs care, because extra identities are *normal* here. A
 * Sunday email routinely carries both that morning's and that evening's orders
 * and {@see OosArchiveEvaluator} imports both, so the staged
 * service count legitimately exceeds the manifest's identity count. An extra is
 * therefore admitted exactly when the staged revision's source key carries an
 * approved entry's origin on that entry's approved date — which is the
 * hash-covered `service_beyond_manifest` explanation the decision of 2026-08-09
 * requires — and rejected otherwise.
 */
class ChurchServiceCorpusExpectation
{
    /**
     * The artifact contract lives on the consumer that validates it, because
     * both lanes produce it: {@see OosApprovedCorpus} for Email and
     * {@see OpenLpApprovedCorpus} for OpenLP. Each producer restates these so
     * existing call sites keep naming their own lane's constant.
     */
    public const string Format = 'crockenhill-historic-corpus-expectation';

    public const int Version = 1;

    public const string EXPECTATION_BATCH_UNSTAGED = 'expectation_batch_unstaged';

    public const string APPROVED_SOURCE_UNSTAGED = 'approved_source_unstaged';

    public const string APPROVED_SOURCE_INPUT_HASH_MISMATCH = 'approved_source_input_hash_mismatch';

    public const string APPROVED_SOURCE_CONTENT_SCOPE_MISMATCH = 'approved_source_content_scope_mismatch';

    public const string MANIFEST_IDENTITY_UNSTAGED = 'manifest_identity_unstaged';

    public const string UNEXPLAINED_IDENTITY = 'unexplained_identity';

    /**
     * Certify every lane a census declares, as one aggregate.
     *
     * There is one expectation artifact per approved manifest and there are two
     * manifests, so a two-lane census is certified from two artifacts rather than
     * from one spanning both: a fused artifact would have to carry a
     * `manifest_hash` neither producer derived, which is exactly the property that
     * makes an expectation checkable.
     *
     * The union in `expected_services` is the reason this aggregation cannot be
     * left to the caller. Email and OpenLP describe *the same services* from
     * different evidence, so summing the lanes' identity counts double-counts every
     * service both lanes cover. Only the identity sets can be combined, and only
     * this class holds them.
     *
     * @param  list<array<string, mixed>>  $expectations
     * @return array<string, mixed>
     */
    public function certifyAll(array $expectations): array
    {
        $lanes = [];
        $sourceKinds = [];
        $identities = [];
        $blockers = [];
        $expectedSources = 0;

        foreach ($expectations as $expectation) {
            $lane = $this->certify($expectation);
            $source = $lane['source'];

            /**
             * A lane has one approved manifest. Two artifacts for the same lane are
             * two conflicting statements of what it should contain, and silently
             * taking either would let a stale manifest certify the round.
             */
            if (isset($sourceKinds[$source])) {
                throw new RuntimeException(
                    "Two corpus expectations describe the {$source} lane; a lane has one approved manifest, not two."
                );
            }

            $sourceKinds[$source] = true;
            $lanes[] = $lane;
            $blockers = [...$blockers, ...$lane['blockers']];
            $expectedSources += $lane['expected_sources'];

            foreach ($lane['identities'] as $identity) {
                $identities[$identity] = true;
            }
        }

        if ($lanes === []) {
            return ['approved' => false, 'lanes' => [], 'source_kinds' => [], 'blockers' => ['expectation_unapproved']];
        }

        return [
            'approved' => true,
            'lanes' => $lanes,
            'source_kinds' => array_keys($sourceKinds),
            'expected_services' => count($identities),
            'expected_sources' => $expectedSources,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * Certify a single lane against its own approved manifest.
     *
     * @param  array<string, mixed>  $expectation
     * @return array<string, mixed>
     */
    public function certify(array $expectation): array
    {
        [$source, $batchHash, $sources, $acceptedHolds] = $this->validated($expectation);

        $approvedIdentities = [];
        $approvedSourceKeys = [];
        $approvedSourcesByKey = [];
        $approvedDateByOrigin = [];

        foreach ($sources as $approved) {
            $identity = $this->identityKey($approved['identity']['date'], $approved['identity']['service']);
            $approvedIdentities[$identity] ??= [];
            $approvedIdentities[$identity][] = $approved['item_key'];
            $approvedSourceKeys[$approved['source_key']] = $approved['item_key'];
            $approvedSourcesByKey[$approved['source_key']] = $approved;
            $approvedDateByOrigin[$approved['origin']] = $approved['identity']['date'];
        }

        $records = ChurchServiceSourceRecord::query()
            ->with('churchService')
            ->where('source', $source)
            ->where('batch_hash', $batchHash)
            ->get();

        $blockers = [];

        if ($records->isEmpty()) {
            $blockers[] = self::EXPECTATION_BATCH_UNSTAGED;
        }

        $stagedSourceKeys = [];
        $stagedIdentities = [];
        $inputHashMismatches = [];
        $contentScopeMismatches = [];

        foreach ($records as $record) {
            $service = $record->churchService;

            if ($service === null) {
                continue;
            }

            $stagedSourceKeys[$record->source_key] = true;

            $approved = $approvedSourcesByKey[$record->source_key] ?? null;

            if ($approved !== null && ! hash_equals($approved['input_hash'], $record->input_hash)) {
                $inputHashMismatches[$record->source_key] = [
                    'item_key' => $approved['item_key'],
                    'source_key' => $record->source_key,
                ];
            }

            if ($approved !== null && $this->expectedPayloadComplete($approved['content_scope']) !== $record->payload_complete) {
                $contentScopeMismatches[$record->source_key] = [
                    'item_key' => $approved['item_key'],
                    'source_key' => $record->source_key,
                ];
            }

            $identity = $this->identityKey($service->date->toDateString(), $service->service->value);
            $stagedIdentities[$identity][] = $record->source_key;
        }

        $unstagedSources = [];

        foreach ($approvedSourceKeys as $sourceKey => $itemKey) {
            if (isset($stagedSourceKeys[$sourceKey]) || isset($acceptedHolds[$itemKey])) {
                continue;
            }

            $unstagedSources[] = ['item_key' => $itemKey, 'source_key' => $sourceKey];
        }

        $unstagedIdentities = [];

        foreach ($approvedIdentities as $identity => $itemKeys) {
            if (isset($stagedIdentities[$identity])) {
                continue;
            }

            /**
             * An identity every one of whose entries the operator accepted as
             * held is a recorded gap, not an unnoticed one. An identity with a
             * single unaccepted entry is still missing.
             */
            $unaccepted = array_values(array_filter(
                $itemKeys,
                static fn (string $itemKey): bool => ! isset($acceptedHolds[$itemKey]),
            ));

            if ($unaccepted === []) {
                continue;
            }

            $unstagedIdentities[] = ['identity' => $this->describe($identity), 'item_keys' => $unaccepted];
        }

        $explainedExtras = [];
        $unexplainedExtras = [];

        foreach ($stagedIdentities as $identity => $sourceKeys) {
            if (isset($approvedIdentities[$identity])) {
                continue;
            }

            [$date] = explode("\0", (string) $identity, 2);
            $origins = [];
            $explained = true;

            foreach (array_unique($sourceKeys) as $sourceKey) {
                $origin = $this->originOf($source, $sourceKey);
                $origins[] = $origin;

                /**
                 * Every revision asserting the identity must trace to an
                 * approved entry on that entry's own approved date. One
                 * untraceable revision makes the identity unexplained, however
                 * many of its siblings are fine.
                 */
                if ($origin === null || ($approvedDateByOrigin[$origin] ?? null) !== $date) {
                    $explained = false;
                }
            }

            $extra = [
                'identity' => $this->describe($identity),
                'origins' => array_values(array_unique(array_filter($origins))),
            ];

            $explained ? $explainedExtras[] = $extra : $unexplainedExtras[] = $extra;
        }

        if ($unstagedSources !== []) {
            $blockers[] = self::APPROVED_SOURCE_UNSTAGED;
        }

        if ($inputHashMismatches !== []) {
            $blockers[] = self::APPROVED_SOURCE_INPUT_HASH_MISMATCH;
        }

        if ($contentScopeMismatches !== []) {
            $blockers[] = self::APPROVED_SOURCE_CONTENT_SCOPE_MISMATCH;
        }

        if ($unstagedIdentities !== []) {
            $blockers[] = self::MANIFEST_IDENTITY_UNSTAGED;
        }

        if ($unexplainedExtras !== []) {
            $blockers[] = self::UNEXPLAINED_IDENTITY;
        }

        return [
            'approved' => true,
            'expectation_hash' => $expectation['expectation_hash'],
            'manifest_hash' => $expectation['manifest_hash'],
            'batch_key' => $expectation['batch_key'],
            'batch_hash' => $batchHash,
            'source' => $source->value,
            'expected_services' => count($approvedIdentities),
            'expected_sources' => count($approvedSourceKeys),
            'identities' => array_map($this->describe(...), array_keys($approvedIdentities)),
            'staged_identities' => count($stagedIdentities),
            'accepted_holds' => array_values($acceptedHolds),
            'unstaged_sources' => $unstagedSources,
            'input_hash_mismatches' => array_values($inputHashMismatches),
            'content_scope_mismatches' => array_values($contentScopeMismatches),
            'unstaged_identities' => $unstagedIdentities,
            'explained_beyond_manifest' => $explainedExtras,
            'unexplained_identities' => $unexplainedExtras,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /**
     * Which lane a staged source key can be traced back to an approved entry
     * through. Only Email has a legitimate beyond-manifest widening: one email
     * carries both of a Sunday's orders, so its source key embeds the message id
     * that identifies the approved entry behind a second identity.
     *
     * An OpenLP `.osz` is exactly one service and the importer is handed that
     * service's approved date and slot, so there is no second order to explain
     * and no origin to trace. Returning null here is the substantive rule, not a
     * gap: any identity staged from OpenLP that the manifest does not name is
     * unexplained by construction, and must fail closed.
     */
    private function originOf(ChurchServiceSource $source, string $sourceKey): ?string
    {
        return match ($source) {
            ChurchServiceSource::Email => OosCurationEntryFactory::originOf($sourceKey),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function fromFile(string $path): array
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('The historic corpus expectation file could not be read.');
        }

        $expectation = json_decode($contents, true);

        if (! is_array($expectation)) {
            throw new RuntimeException('The historic corpus expectation file contains invalid JSON.');
        }

        $this->validated($expectation);

        return $expectation;
    }

    /**
     * @param  array<string, mixed>  $expectation
     * @return array{
     *     0:ChurchServiceSource,
     *     1:string,
     *     2:list<array{item_key:string,origin:string,source_key:string,input_hash:string,identity:array{date:string,service:string},content_scope:string}>,
     *     3:array<string, array{item_key:string,reason:string}>,
     * }
     */
    private function validated(array $expectation): array
    {
        $hash = $expectation['expectation_hash'] ?? null;
        $hashable = $expectation;
        unset($hashable['expectation_hash']);

        if (($expectation['format'] ?? null) !== self::Format
            || ($expectation['version'] ?? null) !== self::Version
            || ! is_string($hash)
            || ! hash_equals(CanonicalJson::hash($hashable), $hash)
            || ! is_string($expectation['batch_key'] ?? null)
            || ! is_string($expectation['manifest_hash'] ?? null)
            || ! is_array($expectation['approved_sources'] ?? null)
            || $expectation['approved_sources'] === []) {
            throw new RuntimeException('Historic corpus expectation format, contents or hash is invalid.');
        }

        $source = is_string($expectation['source'] ?? null)
            ? ChurchServiceSource::tryFrom($expectation['source'])
            : null;
        $batchHash = $expectation['batch_hash'] ?? null;

        if (! $source instanceof ChurchServiceSource
            || ! is_string($batchHash)
            || preg_match('/\A[a-f0-9]{64}\z/', $batchHash) !== 1) {
            throw new RuntimeException('Historic corpus expectation declares an invalid source or batch hash.');
        }

        $sources = [];
        $seen = [];

        foreach ($expectation['approved_sources'] as $approved) {
            if (! is_array($approved)
                || ! is_string($approved['item_key'] ?? null)
                || ! is_string($approved['origin'] ?? null)
                || ! is_string($approved['source_key'] ?? null)
                || ! is_string($approved['input_hash'] ?? null)
                || ! is_string($approved['content_scope'] ?? null)
                || ! in_array($approved['content_scope'], ['full', 'partial'], true)
                || ! is_array($approved['identity'] ?? null)
                || ! is_string($approved['identity']['date'] ?? null)
                || ! is_string($approved['identity']['service'] ?? null)) {
                throw new RuntimeException('Historic corpus expectation contains an invalid approved source.');
            }

            if (isset($seen[$approved['source_key']])) {
                throw new RuntimeException('Historic corpus expectation contains a duplicate source key.');
            }

            $seen[$approved['source_key']] = true;
            $sources[] = [
                'item_key' => $approved['item_key'],
                'origin' => $approved['origin'],
                'source_key' => $approved['source_key'],
                'input_hash' => $approved['input_hash'],
                'identity' => [
                    'date' => $approved['identity']['date'],
                    'service' => $approved['identity']['service'],
                ],
                'content_scope' => $approved['content_scope'],
            ];
        }

        return [$source, $batchHash, $sources, $this->acceptedHolds($expectation, $sources)];
    }

    /**
     * Holds an operator has ruled on, keyed by item key.
     *
     * Optional, and each one requires a written reason for the same reason FR-D9
     * requires one on a manifest exclusion: an accepted hold suppresses a
     * blocker, so an unreasoned entry would be a silent waiver.
     *
     * @param  array<string, mixed>  $expectation
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, array{item_key:string,reason:string}>
     */
    private function acceptedHolds(array $expectation, array $sources): array
    {
        $declared = $expectation['accepted_holds'] ?? [];

        if (! is_array($declared)) {
            throw new RuntimeException('Historic corpus expectation accepted_holds must be a list.');
        }

        $approvedItemKeys = array_column($sources, 'item_key');
        $accepted = [];

        foreach ($declared as $hold) {
            if (! is_array($hold)
                || ! is_string($hold['item_key'] ?? null)
                || ! is_string($hold['reason'] ?? null)
                || trim($hold['reason']) === '') {
                throw new RuntimeException('Historic corpus expectation contains an accepted hold without an item key and reason.');
            }

            /**
             * An acceptance naming an entry the manifest does not include waives
             * nothing and hides a stale decision, so it fails rather than being
             * ignored.
             */
            if (! in_array($hold['item_key'], $approvedItemKeys, true)) {
                throw new RuntimeException(
                    "Historic corpus expectation accepts a hold for {$hold['item_key']}, which is not an approved entry."
                );
            }

            $accepted[$hold['item_key']] = ['item_key' => $hold['item_key'], 'reason' => trim($hold['reason'])];
        }

        return $accepted;
    }

    private function identityKey(string $date, string $service): string
    {
        return "{$date}\0{$service}";
    }

    private function expectedPayloadComplete(string $contentScope): bool
    {
        return $contentScope === 'full';
    }

    private function describe(string $identity): string
    {
        return str_replace("\0", ' ', $identity);
    }
}
